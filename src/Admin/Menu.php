<?php
/**
 * Admin menu registration.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Admin;

use OhMyCache\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the screens and their assets.
 *
 * Assets are enqueued only on this plugin's own pages, keyed off the hook suffix, so the rest
 * of wp-admin is untouched.
 */
final class Menu {

	public const SLUG          = 'oh-my-cache';
	public const SLUG_SETTINGS = 'oh-my-cache-settings';
	public const SLUG_QUEUE    = 'oh-my-cache-queue';
	public const SLUG_SETUP    = 'oh-my-cache-setup';

	/** @var array<int, string> */
	private array $hooks = [];

	public function __construct( private readonly Container $container ) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_pages' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'maybe_redirect_to_wizard' ] );

		/*
		 * Screens that change something act here, before admin-header.php fires admin_notices,
		 * and redirect afterwards. Doing the work inside the page callback would leave the
		 * notices describing the state as it was one action ago, and a refresh would re-run it.
		 */
		add_action( 'admin_init', [ $this, 'handle_screen_actions' ] );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Give each screen a chance to act before anything is rendered.
	 */
	public function handle_screen_actions(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		( new QueuePage( $this->container ) )->handle_request();
		( new Dashboard( $this->container ) )->handle_request();
		( new \OhMyCache\Wizard\Wizard( $this->container ) )->handle_request();
	}

	public function add_pages(): void {
		$capability = $this->capability();

		$this->hooks[] = add_menu_page(
			__( 'Oh My Cache!', 'oh-my-cache' ),
			__( 'Oh My Cache!', 'oh-my-cache' ),
			$capability,
			self::SLUG,
			[ $this, 'render_dashboard' ],
			'dashicons-performance',
			66
		);

		$this->hooks[] = add_submenu_page(
			self::SLUG,
			__( 'Dashboard', 'oh-my-cache' ),
			__( 'Dashboard', 'oh-my-cache' ),
			$capability,
			self::SLUG,
			[ $this, 'render_dashboard' ]
		);

		$this->hooks[] = add_submenu_page(
			self::SLUG,
			__( 'Queue', 'oh-my-cache' ),
			__( 'Queue', 'oh-my-cache' ),
			$capability,
			self::SLUG_QUEUE,
			[ $this, 'render_queue' ]
		);

		$this->hooks[] = add_submenu_page(
			self::SLUG,
			__( 'Settings', 'oh-my-cache' ),
			__( 'Settings', 'oh-my-cache' ),
			$capability,
			self::SLUG_SETTINGS,
			[ $this, 'render_settings' ]
		);

		// Hidden: reachable from the dashboard and from the activation redirect.
		$this->hooks[] = add_submenu_page(
			'',
			__( 'Set up Oh My Cache!', 'oh-my-cache' ),
			__( 'Setup', 'oh-my-cache' ),
			$capability,
			self::SLUG_SETUP,
			[ $this, 'render_setup' ]
		);
	}

	/**
	 * Register the settings group with a single array sanitizer.
	 */
	public function register_settings(): void {
		register_setting(
			'oh_my_cache',
			\OhMyCache\Support\Options::OPTION_SETTINGS,
			[
				'type'              => 'array',
				'sanitize_callback' => [ SettingsPage::class, 'sanitize' ],
				'default'           => \OhMyCache\Support\Options::defaults(),
			]
		);
	}

	/**
	 * Send a fresh activation to the wizard, exactly once.
	 */
	public function maybe_redirect_to_wizard(): void {
		if ( ! get_transient( 'oh_my_cache_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'oh_my_cache_activation_redirect' );

		// Never hijack a bulk or network activation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) || is_network_admin() ) {
			return;
		}

		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_SETUP ) );
		exit;
	}

	/**
	 * Enqueue assets, only on our own screens.
	 *
	 * @param string $hook_suffix Current screen hook.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! in_array( (string) $hook_suffix, $this->hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'oh-my-cache-admin',
			\OhMyCache\PLUGIN_URL . 'assets/css/admin.css',
			[],
			self::asset_version( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'oh-my-cache-admin',
			\OhMyCache\PLUGIN_URL . 'assets/js/admin.js',
			[],
			self::asset_version( 'assets/js/admin.js' ),
			true
		);

		wp_localize_script(
			'oh-my-cache-admin',
			'OhMyCache',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
			]
		);
	}

	/**
	 * Cache-busting version for a bundled asset.
	 *
	 * The file's modification time rather than the plugin version. Tying assets to the plugin
	 * version means any CSS or JS change shipped without a version bump reaches browsers that
	 * already have the old file cached, and they keep running it: the markup is new, the
	 * behaviour is old, and nothing looks broken enough to suspect caching. filemtime makes that
	 * impossible. It falls back to the plugin version if the file is unreadable.
	 *
	 * @param string $relative Path relative to the plugin folder.
	 */
	private static function asset_version( string $relative ): string {
		$path = \OhMyCache\PLUGIN_DIR . $relative;

		$mtime = is_readable( $path ) ? filemtime( $path ) : false;

		return false === $mtime ? \OhMyCache\VERSION : (string) $mtime;
	}

	/* --------------------------------------------------------------------- */

	public function render_dashboard(): void {
		$this->guard();
		( new Dashboard( $this->container ) )->render();
	}

	public function render_queue(): void {
		$this->guard();
		( new QueuePage( $this->container ) )->render();
	}

	public function render_settings(): void {
		$this->guard();
		( new SettingsPage( $this->container ) )->render();
	}

	public function render_setup(): void {
		$this->guard();
		( new \OhMyCache\Wizard\Wizard( $this->container ) )->render();
	}

	/**
	 * Capability check on every screen, not just at registration.
	 *
	 * add_menu_page's capability governs the menu; a direct URL still reaches the callback on
	 * some setups, so the check is repeated where it actually matters.
	 */
	private function guard(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage caching.', 'oh-my-cache' ), '', [ 'response' => 403 ] );
		}
	}

	private function capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}
}
