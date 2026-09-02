<?php
/**
 * Self-diagnosis.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Admin;

use OhMyCache\Cache\Cooldown;
use OhMyCache\Cache\DriverManager;
use OhMyCache\Cache\NginxFastCgiDriver;
use OhMyCache\Cache\Redis\Connection;
use OhMyCache\Cache\RedisDriver;
use OhMyCache\Cloudflare\Credentials;
use OhMyCache\Container;
use OhMyCache\Integrations\WooCommerce;
use OhMyCache\Purge\Sitemaps;
use OhMyCache\Queue\QueueRepository;
use OhMyCache\Queue\Schema;
use OhMyCache\Queue\Scheduler;
use OhMyCache\Support\Migrator;
use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "why is my cache not clearing?" before anyone has to ask.
 *
 * Shared verbatim between the admin screen and `wp oh-my-cache doctor`, so a support conversation can
 * be a single command rather than a screenshot exchange.
 */
final class Doctor {

	public const OK      = 'ok';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	public function __construct( private readonly Container $container ) {}

	/**
	 * Run every check.
	 *
	 * @return array<int, array{id: string, status: string, label: string, detail: string, hint: string}>
	 */
	public function run(): array {
		return array_merge(
			$this->check_storage(),
			$this->check_drivers(),
			$this->check_cloudflare(),
			$this->check_cron(),
			$this->check_sitemaps(),
			$this->check_woocommerce(),
			$this->check_environment()
		);
	}

	/**
	 * Whether anything is in an error state.
	 *
	 * @param array<int, array{status: string}> $results Results.
	 */
	public static function has_errors( array $results ): bool {
		foreach ( $results as $result ) {
			if ( self::ERROR === $result['status'] ) {
				return true;
			}
		}

		return false;
	}

	/* --------------------------------------------------------------------- */

	/**
	 * @return array<int, array<string, string>>
	 */
	private function check_storage(): array {
		$checks = [];

		$table_exists = Schema::exists();

		$checks[] = $this->result(
			'jobs-table',
			$table_exists ? self::OK : self::ERROR,
			__( 'Queue table', 'oh-my-cache' ),
			$table_exists
				? sprintf(
					/* translators: %s: database table name. */
					__( '%s exists.', 'oh-my-cache' ),
					Schema::table()
				)
				: __( 'The jobs table is missing, so nothing can be retried.', 'oh-my-cache' ),
			// Advice only when there is something to act on: telling someone to reactivate the
			// plugin while everything is fine invites them to break a working install.
			$table_exists ? '' : __( 'Deactivate and reactivate the plugin to recreate it.', 'oh-my-cache' )
		);

		$footprint = Options::settings_footprint();
		$over      = $footprint > Options::SETTINGS_BUDGET_BYTES;

		$checks[] = $this->result(
			'autoload-budget',
			$over ? self::WARNING : self::OK,
			__( 'Autoloaded settings size', 'oh-my-cache' ),
			sprintf(
				/* translators: 1: current size in bytes, 2: budget in bytes. */
				__( '%1$s bytes of a %2$s byte budget.', 'oh-my-cache' ),
				number_format_i18n( $footprint ),
				number_format_i18n( Options::SETTINGS_BUDGET_BYTES )
			),
			$over ? __( 'Something oversized is being stored in the autoloaded option; it is loaded on every request.', 'oh-my-cache' ) : ''
		);

		return $checks;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function check_drivers(): array {
		/** @var DriverManager $drivers */
		$drivers = $this->container->get( 'drivers' );

		$checks  = [];
		$enabled = 0;

		foreach ( $drivers->all() as $id => $driver ) {
			if ( ! $driver->is_enabled() ) {
				continue;
			}

			++$enabled;

			$availability = $driver->availability();

			$checks[] = $this->result(
				'driver-' . $id,
				$availability->ok ? self::OK : self::ERROR,
				$driver->label(),
				$availability->ok ? __( 'Ready.', 'oh-my-cache' ) : $availability->reason,
				$availability->hint
			);

			if ( Cooldown::active( $id ) ) {
				$checks[] = $this->result(
					'breaker-' . $id,
					self::WARNING,
					sprintf(
						/* translators: %s: driver label. */
						__( '%s circuit breaker', 'oh-my-cache' ),
						$driver->label()
					),
					sprintf(
						/* translators: %d: seconds remaining. */
						__( 'Open for another %d seconds after repeated inline failures; work is going straight to the queue.', 'oh-my-cache' ),
						Cooldown::remaining( $id )
					),
					''
				);
			}
		}

		if ( 0 === $enabled ) {
			$checks[] = $this->result(
				'drivers',
				self::ERROR,
				__( 'Purge drivers', 'oh-my-cache' ),
				__( 'No driver is enabled, so nothing is ever purged.', 'oh-my-cache' ),
				__( 'Enable at least one of NGINX, Redis or Cloudflare in Settings.', 'oh-my-cache' )
			);
		}

		// The Redis extension is worth reporting on even when the driver is off.
		$checks[] = $this->result(
			'phpredis',
			Connection::extension_ok() ? self::OK : self::WARNING,
			__( 'phpredis extension', 'oh-my-cache' ),
			Connection::extension_ok()
				? sprintf(
					/* translators: 1: installed version, 2: version the plugin is tested against. */
					__( 'Version %1$s installed (tested against %2$s).', 'oh-my-cache' ),
					Connection::extension_version(),
					Connection::TESTED_AGAINST
				)
				: sprintf(
					/* translators: 1: installed version or "not installed", 2: minimum version. */
					__( '%1$s; the Redis driver needs %2$s or newer.', 'oh-my-cache' ),
					Connection::extension_version() ?: __( 'Not installed', 'oh-my-cache' ),
					Connection::MIN_EXTENSION
				),
			''
		);

		$redis = $drivers->get( 'redis' );

		if ( $redis instanceof RedisDriver && $redis->is_enabled() && Connection::extension_ok() ) {
			$connection = $redis->connection();
			$reachable  = $connection->ping();

			$checks[] = $this->result(
				'redis-server',
				$reachable ? self::OK : self::ERROR,
				__( 'Redis server', 'oh-my-cache' ),
				$reachable
					? sprintf(
						/* translators: 1: Redis server version, 2: key prefix. */
						__( 'Reachable, version %1$s, prefix "%2$s".', 'oh-my-cache' ),
						$connection->server_version(),
						$redis->prefix()
					)
					: (string) $connection->error(),
				''
			);
		}

		$nginx = $drivers->get( 'nginx' );

		if ( $nginx instanceof NginxFastCgiDriver && $nginx->is_enabled() && 'http' === Options::get( 'drivers.nginx.method' ) ) {
			$checks[] = $this->result(
				'nginx-self-request',
				self::WARNING,
				__( 'NGINX HTTP purge mode', 'oh-my-cache' ),
				__( 'This mode makes the site request its own hostname. On a small PHP-FPM pool that request can block on itself.', 'oh-my-cache' ),
				__( 'Make sure pm.max_children is at least 5, or set this driver to queue-only dispatch.', 'oh-my-cache' )
			);
		}

		return $checks;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function check_cloudflare(): array {
		/** @var DriverManager $drivers */
		$drivers = $this->container->get( 'drivers' );

		$driver = $drivers->get( 'cloudflare' );

		if ( ! $driver || ! $driver->is_enabled() ) {
			return [];
		}

		$checks = [];
		$source = Credentials::token_source();

		$checks[] = $this->result(
			'cf-token-source',
			'database' === $source ? self::WARNING : self::OK,
			__( 'Cloudflare token storage', 'oh-my-cache' ),
			match ( $source ) {
				'env'      => __( 'Supplied by an environment variable; never written to the database.', 'oh-my-cache' ),
				'constant' => __( 'Supplied by a constant in wp-config.php; never written to the database.', 'oh-my-cache' ),
				'database' => __( 'Stored in the database, so it appears in every backup and staging copy.', 'oh-my-cache' ),
				default    => __( 'No token is configured.', 'oh-my-cache' ),
			},
			'database' === $source
				? __( 'Move it to the OH_MY_CACHE_CF_API_TOKEN environment variable or constant; the plugin will stop persisting it.', 'oh-my-cache' )
				: ''
		);

		$orphans = Options::orphaned_secrets();

		if ( $orphans ) {
			$checks[] = $this->result(
				'cf-orphan-secret',
				self::WARNING,
				__( 'Leftover stored credentials', 'oh-my-cache' ),
				__( 'A credential exists in the database while the environment also supplies one. The environment wins, so the stored copy is dead weight.', 'oh-my-cache' ),
				__( 'Delete the stored copy from the Cloudflare settings tab.', 'oh-my-cache' )
			);
		}

		$ttl = (int) Options::get( 'edge.ttl_seconds', 0 );

		if ( $ttl > 0 ) {
			/** @var QueueRepository $queue */
			$queue  = $this->container->get( 'queue' );
			$counts = $queue->counts();
			$broken = ! $driver->availability()->ok || ( $counts['dead'] ?? 0 ) > 0;

			$checks[] = $this->result(
				'edge-ttl-interlock',
				$broken ? self::ERROR : self::OK,
				__( 'Edge TTL safety', 'oh-my-cache' ),
				$broken
					? __( 'Pages are pinned at the edge while purging is broken or has dead jobs. Stale HTML will be served for hours.', 'oh-my-cache' )
					: sprintf(
						/* translators: %d: TTL in seconds. */
						__( 'Guest HTML is cached at the edge for %d seconds, and purging is healthy.', 'oh-my-cache' ),
						$ttl
					),
				$broken ? __( 'Fix the Cloudflare driver or retry the dead jobs, or set the edge TTL back to zero until it is fixed.', 'oh-my-cache' ) : ''
			);
		}

		return $checks;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function check_cron(): array {
		$checks  = [];
		$stalled = Scheduler::looks_stalled();
		$age     = Scheduler::last_run_age();

		$checks[] = $this->result(
			'cron',
			$stalled ? self::ERROR : self::OK,
			__( 'Queue worker', 'oh-my-cache' ),
			null === $age
				? __( 'The worker has never run.', 'oh-my-cache' )
				: sprintf(
					/* translators: %s: human readable time difference. */
					__( 'Last ran %s ago.', 'oh-my-cache' ),
					human_time_diff( time() - $age, time() )
				),
			$stalled
				? __( 'Work is waiting but the worker is not running. If DISABLE_WP_CRON is set, add a system crontab entry: * * * * * wp oh-my-cache queue run --all --quiet', 'oh-my-cache' )
				: ''
		);

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$checks[] = $this->result(
				'wp-cron-disabled',
				self::WARNING,
				__( 'WP-Cron', 'oh-my-cache' ),
				__( 'DISABLE_WP_CRON is set, so WordPress will not run scheduled work on its own.', 'oh-my-cache' ),
				__( 'A real crontab must call wp-cron.php or `wp oh-my-cache queue run --all`, otherwise queued purges never happen.', 'oh-my-cache' )
			);
		}

		return $checks;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function check_sitemaps(): array {
		if ( ! Options::flag( 'purge.sitemaps', true ) ) {
			return [];
		}

		$sitemaps = new Sitemaps();
		$urls     = $sitemaps->all();

		// The URLs, not just the name: "Yoast SEO" says a plugin is installed, the URLs say what
		// will be cleared, and that is the part that can be wrong.
		return [
			$this->result(
				'sitemaps',
				$urls ? self::OK : self::WARNING,
				__( 'Sitemaps', 'oh-my-cache' ),
				$urls
					? sprintf(
						/* translators: 1: name of the plugin generating sitemaps, 2: comma separated URLs. */
						__( 'Generated by %1$s. These are cleared: %2$s', 'oh-my-cache' ),
						$sitemaps->provider_label(),
						implode( ', ', $urls )
					)
					: __( 'No sitemap generator was found, so none is being cleared.', 'oh-my-cache' ),
				$this->sitemap_hint( $sitemaps, (bool) $urls )
			),
		];
	}

	/**
	 * What to say underneath the sitemap row.
	 *
	 * @param Sitemaps $sitemaps Sitemap reader.
	 * @param bool     $found    Whether anything was found at all.
	 */
	private function sitemap_hint( Sitemaps $sitemaps, bool $found ): string {
		if ( ! $found ) {
			return __( 'If something here does generate sitemaps, add its URL under When to purge.', 'oh-my-cache' );
		}

		if ( $sitemaps->discovered() ) {
			return '';
		}

		// Read once and empty means the index answered with nothing.
		if ( null !== $sitemaps->discovered_at() ) {
			return __( 'The index listed no files, so only the index is cleared. Usually that means the site cannot make requests to itself.', 'oh-my-cache' );
		}

		return __( 'Only the index is known so far. The rest is read from it in the next few minutes, then twice a day.', 'oh-my-cache' );
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function check_woocommerce(): array {
		if ( ! WooCommerce::is_active() ) {
			return [];
		}

		$excluded = [];

		foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
			$link = get_permalink( (int) wc_get_page_id( $page ) );

			if ( is_string( $link ) ) {
				$excluded[] = (string) wp_parse_url( $link, PHP_URL_PATH );
			}
		}

		$watching = Options::flag( 'purge.woocommerce', true );

		return [
			$this->result(
				'woocommerce',
				$watching ? self::OK : self::WARNING,
				__( 'WooCommerce', 'oh-my-cache' ),
				$watching
					? sprintf(
						/* translators: %s: comma separated paths. */
						__( 'Stock, price and variation changes clear the cache. Never cached at the edge: %s', 'oh-my-cache' ),
						implode( ', ', $excluded )
					)
					: __( 'Stock and price changes clear nothing while that switch is off.', 'oh-my-cache' ),
				$watching
					? ''
					: __( 'WooCommerce changes stock without saving the post, so a sold-out product keeps showing as in stock.', 'oh-my-cache' )
			),
		];
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function check_environment(): array {
		$checks = [];

		if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
			$allowed = defined( 'WP_ACCESSIBLE_HOSTS' ) ? (string) constant( 'WP_ACCESSIBLE_HOSTS' ) : '';
			$ok      = str_contains( $allowed, 'api.cloudflare.com' );

			$checks[] = $this->result(
				'http-block',
				$ok ? self::OK : self::WARNING,
				__( 'Outbound HTTP', 'oh-my-cache' ),
				$ok
					? __( 'External requests are restricted, but Cloudflare is allowed.', 'oh-my-cache' )
					: __( 'WP_HTTP_BLOCK_EXTERNAL is on and api.cloudflare.com is not allowed, so Cloudflare purges will fail.', 'oh-my-cache' ),
				$ok ? '' : __( 'Add api.cloudflare.com to WP_ACCESSIBLE_HOSTS.', 'oh-my-cache' )
			);
		}

		$donors = Migrator::active_donors();

		if ( $donors ) {
			$checks[] = $this->result(
				'donor-plugins',
				self::WARNING,
				__( 'Overlapping plugins', 'oh-my-cache' ),
				sprintf(
					/* translators: %s: comma separated plugin names. */
					__( '%s is still active and will purge the same URLs.', 'oh-my-cache' ),
					implode( ', ', $donors )
				),
				__( 'Double purging is not harmful, but it doubles Cloudflare API usage and can trigger rate limiting. Deactivate the old plugin once you are happy here.', 'oh-my-cache' )
			);
		}

		return $checks;
	}

	/**
	 * @param string $id     Check id.
	 * @param string $status One of OK, WARNING, ERROR.
	 * @param string $label  Short label.
	 * @param string $detail What was found.
	 * @param string $hint   What to do about it.
	 * @return array<string, string>
	 */
	private function result( string $id, string $status, string $label, string $detail, string $hint = '' ): array {
		return [
			'id'     => $id,
			'status' => $status,
			'label'  => $label,
			'detail' => $detail,
			'hint'   => $hint,
		];
	}
}
