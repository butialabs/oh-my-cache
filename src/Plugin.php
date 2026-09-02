<?php
/**
 * Bootstrap and wiring.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache;

use OhMyCache\Api\TriggerRegistry;
use OhMyCache\Cache\CloudflareDriver;
use OhMyCache\Cache\DriverManager;
use OhMyCache\Cache\NginxFastCgiDriver;
use OhMyCache\Cache\RedisDriver;
use OhMyCache\Cloudflare\Client;
use OhMyCache\Http\EdgeHeaders;
use OhMyCache\Integrations\WooCommerce;
use OhMyCache\Http\TrueClientIp;
use OhMyCache\Purge\Coordinator;
use OhMyCache\Purge\Hooks;
use OhMyCache\Purge\LegacyHooks;
use OhMyCache\Purge\Preloader;
use OhMyCache\Purge\Sitemaps;
use OhMyCache\Purge\UrlCollector;
use OhMyCache\Queue\QueueRepository;
use OhMyCache\Queue\Schema;
use OhMyCache\Queue\Scheduler;
use OhMyCache\Queue\Worker;
use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Single entry point. Everything else hangs off the container it builds.
 */
final class Plugin {

	private static ?self $instance = null;

	private Container $container;

	private function __construct() {
		$this->container = new Container();
		$this->register_services();
	}

	/**
	 * Build the plugin and register hooks. Called once, from the main file.
	 */
	public static function boot(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_hooks();
		}

		return self::$instance;
	}

	/**
	 * The running instance, or null before boot.
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * Convenience accessor used across the plugin and by the public API.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 */
	public static function service( string $id ): mixed {
		$plugin = self::instance();

		return $plugin ? $plugin->container->get( $id ) : null;
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Register the service factories. Nothing is constructed here.
	 */
	private function register_services(): void {
		$this->container->set(
			'drivers',
			static function (): DriverManager {
				$manager = new DriverManager();
				$manager->register( new NginxFastCgiDriver() );
				$manager->register( new RedisDriver() );
				$manager->register( new CloudflareDriver() );

				/**
				 * Register additional purge drivers.
				 *
				 * A third-party driver joins the same queue, backoff, circuit breaker and
				 * dashboard as the built-in ones.
				 *
				 * @param DriverManager $manager Driver registry.
				 */
				do_action( 'oh_my_cache_register_drivers', $manager );

				return $manager;
			}
		);

		$this->container->set( 'queue', static fn (): QueueRepository => new QueueRepository() );

		$this->container->set( 'collector', static fn (): UrlCollector => new UrlCollector() );

		$this->container->set(
			'coordinator',
			static fn ( Container $c ): Coordinator => new Coordinator(
				$c->get( 'drivers' ),
				$c->get( 'queue' )
			)
		);

		$this->container->set(
			'worker',
			static fn ( Container $c ): Worker => new Worker(
				$c->get( 'queue' ),
				$c->get( 'drivers' )
			)
		);

		$this->container->set( 'triggers', static fn (): TriggerRegistry => new TriggerRegistry() );

		$this->container->set( 'cloudflare', static fn (): Client => new Client() );

		$this->container->set(
			'preloader',
			static fn ( Container $c ): Preloader => new Preloader( $c->get( 'queue' ) )
		);
	}

	/**
	 * Register WordPress hooks.
	 */
	private function register_hooks(): void {
		/*
		 * No load_plugin_textdomain() call. Since WordPress 4.6 translations are loaded
		 * automatically for a plugin whose text domain matches its folder, which ours does, and
		 * calling it by hand is now discouraged. Every translatable string still lives inside a
		 * hook callback, because a __() before `init` trips the _load_textdomain_just_in_time
		 * notice that WordPress 6.7 introduced.
		 */

		/*
		 * Schema upgrades run here rather than only on activation, so a plugin update installs
		 * the table without anyone having to deactivate and reactivate. The version check is a
		 * single autoloaded option read, so the steady state costs nothing.
		 */
		add_action( 'plugins_loaded', [ Schema::class, 'maybe_upgrade' ], 5 );

		// Header rewrites that must beat other plugins reading REMOTE_ADDR.
		TrueClientIp::maybe_register();

		if ( ! $this->is_enabled() ) {
			return;
		}

		// Cron plumbing.
		add_filter( 'cron_schedules', [ Scheduler::class, 'add_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( 'init', [ Scheduler::class, 'schedule' ] );
		add_action( Scheduler::HOOK_WORKER, [ $this, 'run_worker' ] );
		add_action( Scheduler::HOOK_NOW, [ $this, 'run_worker' ] );
		add_action( Scheduler::HOOK_CLEANUP, [ $this, 'run_cleanup' ] );
		add_action( Scheduler::HOOK_CF_IPS, [ TrueClientIp::class, 'refresh_ranges' ] );
		add_action( Scheduler::HOOK_SITEMAPS, [ $this, 'refresh_sitemaps' ] );
		add_action( Scheduler::HOOK_SITEMAPS_NOW, [ $this, 'refresh_sitemaps' ] );

		/*
		 * The DISABLE_WP_CRON escape hatch. When cron cannot be relied on, drain a little of the
		 * queue at the very end of the request. Local drivers only: running the HTTP-based ones
		 * here would make the request wait on a request to itself.
		 */
		if ( in_array( (string) Options::get( 'queue.worker_mode', 'cron' ), [ 'inline', 'both' ], true ) ) {
			add_action( 'shutdown', [ $this, 'drain_inline' ], 100 );
		}

		// Purge collection and dispatch.
		( new Hooks( $this->container ) )->register();
		( new LegacyHooks( $this->container ) )->register();
		( new WooCommerce( $this->container ) )->register();

		// Edge caching headers, which are what make the guest Cache Rule do anything.
		EdgeHeaders::register();

		// Admin-only code stays behind is_admin() so the front end never pays for it.
		if ( is_admin() ) {
			( new Admin\Menu( $this->container ) )->register();
			( new Admin\Ajax( $this->container ) )->register();
			( new Admin\Notices( $this->container ) )->register();
		}

		if ( Options::flag( 'admin_bar', true ) ) {
			( new Admin\AdminBar( $this->container ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'oh-my-cache', Cli\Command::class );
		}
	}

	/**
	 * Whether the plugin should do anything at all.
	 */
	private function is_enabled(): bool {
		if ( defined( 'OH_MY_CACHE_DISABLE' ) && constant( 'OH_MY_CACHE_DISABLE' ) ) {
			return false;
		}

		if ( 'true' === strtolower( (string) getenv( 'OH_MY_CACHE_DISABLE' ) ) ) {
			return false;
		}

		return Options::flag( 'enabled', true );
	}

	/**
	 * Cron callback: drain the queue.
	 */
	public function run_worker(): void {
		/** @var Worker $worker */
		$worker = $this->container->get( 'worker' );
		$worker->run();
	}

	/**
	 * Drain a few local jobs at the end of the request.
	 *
	 * Deliberately small and deliberately local-only. This exists so a site with DISABLE_WP_CRON
	 * and no system crontab still clears its own page cache, not to replace the worker.
	 */
	public function drain_inline(): void {
		if ( Options::queue_depth() < 1 ) {
			return;
		}

		/** @var DriverManager $drivers */
		$drivers = $this->container->get( 'drivers' );
		/** @var Worker $worker */
		$worker = $this->container->get( 'worker' );

		$deadline = microtime( true ) + 3.0;

		foreach ( $drivers->enabled() as $id => $driver ) {
			if ( $driver->is_remote() || microtime( true ) >= $deadline ) {
				continue;
			}

			$worker->run( 5, $id );
		}
	}

	/**
	 * Cron callback: retention sweep and depth reconciliation.
	 */
	public function run_cleanup(): void {
		/** @var QueueRepository $queue */
		$queue = $this->container->get( 'queue' );
		$queue->gc();
	}

	/**
	 * Read the sitemap index and remember what it lists.
	 *
	 * On cron, because it makes an HTTP request to this site and a purge must not wait for one.
	 */
	public function refresh_sitemaps(): void {
		if ( ! Options::flag( 'purge.sitemaps', true ) ) {
			return;
		}

		( new Sitemaps() )->refresh();
	}
}
