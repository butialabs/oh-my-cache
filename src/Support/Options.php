<?php
/**
 * Settings access: options, environment variables and constants.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the plugin options, plus environment and constant overrides.
 *
 * Only OPTION_SETTINGS and OPTION_QUEUE_DEPTH autoload. Everything else loads on demand,
 * because a cache plugin that inflates `alloptions` on every pageview would be self-defeating.
 * OPTION_SETTINGS carries a hard budget; see settingsFootprint().
 */
final class Options {

	public const OPTION_SETTINGS    = 'oh_my_cache_settings';
	public const OPTION_SECRETS     = 'oh_my_cache_secrets';
	public const OPTION_CF_STATE    = 'oh_my_cache_cf_state';
	public const OPTION_WIZARD      = 'oh_my_cache_wizard';
	public const OPTION_DB_VERSION  = 'oh_my_cache_db_version';
	public const OPTION_QUEUE_DEPTH = 'oh_my_cache_queue_depth';
	public const OPTION_LAST_RUN    = 'oh_my_cache_last_worker_run';
	public const OPTION_CF_IPS      = 'oh_my_cache_cf_ips';

	/**
	 * Extra paths cleared alongside every purge.
	 *
	 * Kept out of the settings tree and out of autoload on purpose: the list is unbounded, and
	 * the whole reason for the 4 KB budget is to stop something like it reaching alloptions on
	 * every request.
	 */
	public const OPTION_CUSTOM_URLS = 'oh_my_cache_custom_urls';

	/** Serialized byte budget for the single autoloaded settings option. */
	public const SETTINGS_BUDGET_BYTES = 4096;

	/**
	 * Secrets that are never persisted when supplied by env or constant.
	 *
	 * Cloudflare authentication is API token only. The global API key is deliberately absent: it
	 * grants full account access, cannot be scoped to one zone or one permission, and cannot be
	 * revoked without breaking everything else that uses it. There is no good reason to ask for
	 * one, so this plugin never does. Dropping it also removes the need for an account email,
	 * which only ever existed to pair with that key.
	 */
	public const SECRET_KEYS = [
		'cf_api_token',
		'redis_username',
		'redis_password',
	];

	private static ?array $settings_cache = null;

	/** @var array<int, string>|null */
	private static ?array $custom_urls_cache = null;

	/**
	 * Default settings tree.
	 *
	 * Deliberately flat and small. Anything unbounded (URL lists, zone snapshots) belongs in a
	 * non-autoloaded option instead.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'enabled'                  => true,
			'admin_bar'                => true,
			'drivers'                  => [
				'nginx'      => [
					'enabled'      => false,
					'dispatch'     => 'inherit',
					'method'       => 'unlink',
					'cache_path'   => '',
					'levels'       => '1:2',
					'cache_key'    => '$scheme$request_method$host$request_uri',
					'purge_prefix' => '/purge',
				],
				'redis'      => [
					'enabled'         => false,
					'dispatch'        => 'inherit',
					'host'            => '127.0.0.1',
					'port'            => 6379,
					'socket'          => '',
					'database'        => 0,
					'prefix'          => 'nginx-cache:',
					'tls'             => false,
					'connect_timeout' => 1.0,
					'read_timeout'    => 2.0,
					'retry_interval'  => 100,
					'max_retries'     => 2,
					'scan_count'      => 1000,
				],
				'cloudflare' => [
					'enabled'  => false,
					'dispatch' => 'inherit',
				],
			],
			'purge'                    => [
				'homepage_on_edit'   => true,
				'homepage_on_delete' => true,
				'archives_on_edit'   => true,
				'archives_on_delete' => true,
				'post_on_edit'       => true,
				'on_new_comment'     => true,
				'on_comment_status'  => true,
				'on_attachment_edit' => true,
				'on_term_change'     => true,
				'on_theme_switch'    => true,
				'on_menu_change'     => true,
				'feeds'              => true,
				'sitemaps'           => true,
				'woocommerce'        => true,
				'pagination_depth'   => 5,
				'max_urls'           => 1000,
				/*
				 * Exceptions only, not a list of everything switched on: fifty post types would
				 * mean fifty keys in an option loaded on every request, and a type registered
				 * after the last save would be missing from it and silently stop clearing.
				 */
				'post_types_off'     => [],
			],
			'cdn'                      => [
				'provider' => 'cloudflare',
			],
			'dispatch'                 => [
				'mode'                     => 'realtime',
				'inline_budget'            => 8.0,
				'inline_on_frontend'       => false,
				'inline_failure_threshold' => 3,
				'inline_cooldown'          => 300,
			],
			'queue'                    => [
				'max_attempts'        => 6,
				'batch_size'          => 10,
				'budget_seconds'      => 20,
				'worker_mode'         => 'cron',
				'retain_done_minutes' => 60,
				'retain_dead_days'    => 30,
			],
			'edge'                     => [
				'ttl_seconds'            => 0,
				'true_client_ip'         => false,
				'force_https_from_proto' => false,
			],
			/*
			 * Preloading is always a deliberate action, from the dashboard button or WP-CLI,
			 * never something that fires automatically after a purge. So there is no on/off
			 * flag here: a setting nobody reads is the kind of dead weight this plugin exists
			 * to avoid.
			 */
			'preload'                  => [
				'concurrency' => 3,
			],
			'delete_data_on_uninstall' => false,
		];
	}

	/**
	 * Whole settings tree with defaults merged in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$stored = get_option( self::OPTION_SETTINGS, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		self::$settings_cache = self::merge_deep( self::defaults(), $stored );

		return self::$settings_cache;
	}

	/**
	 * Read a dot separated path, for example drivers.redis.prefix.
	 *
	 * @param string $path          Dot path.
	 * @param mixed  $default_value Returned when the path is absent.
	 * @return mixed
	 */
	public static function get( string $path, mixed $default_value = null ): mixed {
		$node = self::all();

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				return $default_value;
			}
			$node = $node[ $segment ];
		}

		return $node;
	}

	/**
	 * Boolean read, tolerant of the string values a form posts.
	 *
	 * @param string $path    Dot path.
	 * @param bool   $default_value Fallback.
	 */
	public static function flag( string $path, bool $default_value = false ): bool {
		$value = self::get( $path, $default_value );

		return filter_var( $value, FILTER_VALIDATE_BOOL );
	}

	/**
	 * Post types the operator has switched off, by slug.
	 *
	 * @return array<int, string>
	 */
	public static function disabled_post_types(): array {
		$off = self::get( 'purge.post_types_off', [] );

		return is_array( $off ) ? array_values( array_filter( $off, 'is_string' ) ) : [];
	}

	/**
	 * Whether changes to this post type clear anything.
	 *
	 * On by default, including for a type registered after the last save. Governs the automatic
	 * triggers only: a caller asking through the API or WP-CLI has said what it wants.
	 *
	 * @param string $post_type Post type slug.
	 */
	public static function post_type_enabled( string $post_type ): bool {
		return ! in_array( $post_type, self::disabled_post_types(), true );
	}

	/**
	 * Which local cache backend is in use: nginx, redis, or none.
	 *
	 * The two are mutually exclusive. A site has one page cache in front of PHP, not two, and
	 * offering both at once only invites a configuration where one of them silently purges
	 * nothing. Storage stays per-driver so each driver reads its own flag; this is the single
	 * question the interface asks.
	 */
	public static function local_driver(): string {
		if ( self::flag( 'drivers.nginx.enabled', false ) ) {
			return 'nginx';
		}

		if ( self::flag( 'drivers.redis.enabled', false ) ) {
			return 'redis';
		}

		return 'none';
	}

	/* --------------------------------------------------------------------- */
	/* Extra paths cleared on every purge                                     */
	/* --------------------------------------------------------------------- */

	/**
	 * The stored text, one path per line, exactly as it is shown in the textarea.
	 */
	public static function custom_urls_raw(): string {
		return (string) get_option( self::OPTION_CUSTOM_URLS, '' );
	}

	/**
	 * The stored paths resolved to absolute URLs.
	 *
	 * @return array<int, string>
	 */
	public static function custom_urls(): array {
		if ( null !== self::$custom_urls_cache ) {
			return self::$custom_urls_cache;
		}

		$raw = self::custom_urls_raw();

		self::$custom_urls_cache = '' === trim( $raw )
			? []
			: Url::normalize_all( preg_split( '/\R/', $raw ) ?: [] );

		return self::$custom_urls_cache;
	}

	/**
	 * Store the textarea contents.
	 *
	 * @param string $raw Already sanitised text.
	 */
	public static function save_custom_urls( string $raw ): bool {
		self::$custom_urls_cache = null;

		return update_option( self::OPTION_CUSTOM_URLS, $raw, false );
	}

	/**
	 * Clean up what somebody typed, and say what was thrown away.
	 *
	 * Two things happen here that matter. A line without a scheme or a leading slash gets one,
	 * because Url::normalize() returns nothing for a bare "llms.txt" and that is exactly how
	 * people write it; without this the field would accept something that silently never clears.
	 *
	 * And a line containing a wildcard is dropped. A "/shop/*" would become a literal URL with an
	 * asterisk in it, matching nothing on NGINX and nothing on Cloudflare below Enterprise.
	 * Accepting it would manufacture the exact silent failure this plugin exists to prevent.
	 * Purging by pattern has its own entry point, oh_my_cache_purge_pattern().
	 *
	 * @param string $raw Raw textarea contents.
	 * @return array{text: string, rejected: array<int, string>}
	 */
	public static function sanitize_custom_urls( string $raw ): array {
		$lines    = preg_split( '/\R/', $raw ) ?: [];
		$clean    = [];
		$rejected = [];

		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );

			if ( '' === $line ) {
				continue;
			}

			if ( str_contains( $line, '*' ) ) {
				$rejected[] = $line;
				continue;
			}

			if ( ! preg_match( '#^https?://#i', $line ) && ! str_starts_with( $line, '/' ) ) {
				$line = '/' . $line;
			}

			// Anything that cannot resolve to a real URL is not worth storing.
			if ( '' === Url::normalize( $line ) ) {
				$rejected[] = $line;
				continue;
			}

			$clean[ $line ] = true;
		}

		return [
			'text'     => implode( "\n", array_keys( $clean ) ),
			'rejected' => $rejected,
		];
	}

	/**
	 * Persist the whole settings tree.
	 *
	 * @param array<string, mixed> $settings Full settings tree.
	 */
	public static function save( array $settings ): bool {
		self::$settings_cache = null;

		return update_option( self::OPTION_SETTINGS, $settings, true );
	}

	/**
	 * Serialized size of the autoloaded settings option, for the budget test.
	 */
	public static function settings_footprint(): int {
		return strlen( maybe_serialize( self::all() ) );
	}

	/* --------------------------------------------------------------------- */
	/* Secrets                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Resolve a secret: environment, then constant, then database.
	 *
	 * @param string $key One of SECRET_KEYS.
	 */
	public static function secret( string $key ): string {
		$external = self::external_secret( $key );
		if ( null !== $external ) {
			return $external;
		}

		$stored = get_option( self::OPTION_SECRETS, [] );

		return is_array( $stored ) && isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
	}

	/**
	 * Where a secret came from: env, constant, database or unset.
	 *
	 * The settings form uses this to decide whether to render an input at all, and the
	 * sanitizer uses it to refuse persisting a value the environment already supplies.
	 *
	 * @param string $key One of SECRET_KEYS.
	 */
	public static function secret_source( string $key ): string {
		$name = self::constant_name( $key );

		$env = getenv( $name );
		if ( is_string( $env ) && '' !== $env ) {
			return 'env';
		}

		if ( defined( $name ) && '' !== (string) constant( $name ) ) {
			return 'constant';
		}

		$stored = get_option( self::OPTION_SECRETS, [] );
		if ( is_array( $stored ) && ! empty( $stored[ $key ] ) ) {
			return 'database';
		}

		return 'unset';
	}

	/**
	 * True when env or a constant supplies this secret, meaning it must never reach the database.
	 *
	 * @param string $key One of SECRET_KEYS.
	 */
	public static function secret_is_external( string $key ): bool {
		return in_array( self::secret_source( $key ), [ 'env', 'constant' ], true );
	}

	/**
	 * Persist secrets, silently dropping any key the environment already provides.
	 *
	 * @param array<string, string> $secrets Key to value.
	 */
	public static function save_secrets( array $secrets ): bool {
		$stored = get_option( self::OPTION_SECRETS, [] );
		$stored = is_array( $stored ) ? $stored : [];

		foreach ( $secrets as $key => $value ) {
			if ( ! in_array( $key, self::SECRET_KEYS, true ) ) {
				continue;
			}

			// Environment wins. Refuse to persist even when the form posted a value.
			if ( self::secret_is_external( $key ) ) {
				continue;
			}

			$value = (string) $value;
			if ( '' === $value ) {
				unset( $stored[ $key ] );
				continue;
			}

			$stored[ $key ] = $value;
		}

		return update_option( self::OPTION_SECRETS, $stored, false );
	}

	/**
	 * Secrets sitting in the database while the environment also supplies one.
	 *
	 * @return array<int, string>
	 */
	public static function orphaned_secrets(): array {
		$stored = get_option( self::OPTION_SECRETS, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$orphans = [];
		foreach ( self::SECRET_KEYS as $key ) {
			if ( ! empty( $stored[ $key ] ) && null !== self::external_secret( $key ) ) {
				$orphans[] = $key;
			}
		}

		return $orphans;
	}

	/**
	 * Forget the database copy of a secret the environment now supplies.
	 *
	 * @param string $key One of SECRET_KEYS.
	 */
	public static function forget_stored_secret( string $key ): bool {
		$stored = get_option( self::OPTION_SECRETS, [] );
		if ( ! is_array( $stored ) || ! isset( $stored[ $key ] ) ) {
			return false;
		}

		unset( $stored[ $key ] );

		return update_option( self::OPTION_SECRETS, $stored, false );
	}

	/**
	 * Environment or constant value for a secret, or null.
	 *
	 * @param string $key One of SECRET_KEYS.
	 */
	private static function external_secret( string $key ): ?string {
		$name = self::constant_name( $key );

		$env = getenv( $name );
		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}

		if ( defined( $name ) ) {
			$value = (string) constant( $name );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Environment and constant name for a settings or secret key.
	 *
	 * @param string $key Key name.
	 */
	public static function constant_name( string $key ): string {
		return 'OH_MY_CACHE_' . strtoupper( $key );
	}

	/**
	 * Read a non secret override from env or constant, with a legacy constant fallback.
	 *
	 * @param string             $key    Key name, e.g. nginx_path.
	 * @param array<int, string> $legacy Legacy constant names to honour.
	 */
	public static function external( string $key, array $legacy = [] ): ?string {
		$name = self::constant_name( $key );

		$env = getenv( $name );
		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}

		if ( defined( $name ) ) {
			return (string) constant( $name );
		}

		foreach ( $legacy as $constant ) {
			if ( defined( $constant ) ) {
				return (string) constant( $constant );
			}
		}

		return null;
	}

	/* --------------------------------------------------------------------- */
	/* Cloudflare state                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Read the Cloudflare state bag, or one key from it.
	 *
	 * @param string $key           Optional key.
	 * @param mixed  $default_value Fallback for a single key.
	 * @return mixed
	 */
	public static function cf_state( string $key = '', mixed $default_value = null ): mixed {
		$state = get_option( self::OPTION_CF_STATE, [] );
		$state = is_array( $state ) ? $state : [];

		if ( '' === $key ) {
			return $state;
		}

		return $state[ $key ] ?? $default_value;
	}

	/**
	 * Merge a patch into the Cloudflare state bag.
	 *
	 * @param array<string, mixed> $patch Keys to merge.
	 */
	public static function set_cf_state( array $patch ): bool {
		$state = self::cf_state();

		return update_option( self::OPTION_CF_STATE, array_merge( (array) $state, $patch ), false );
	}

	/* --------------------------------------------------------------------- */
	/* Queue depth hint                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Cheap "anything to do?" hint, autoloaded so the common empty case costs no query at all.
	 *
	 * It is a hint, not the truth. The daily GC reconciles it against a real COUNT so a drift
	 * corrects itself instead of wedging the queue.
	 */
	public static function queue_depth(): int {
		return (int) get_option( self::OPTION_QUEUE_DEPTH, 0 );
	}

	/**
	 * Adjust the hint.
	 *
	 * @param int $by Delta, may be negative.
	 */
	public static function bump_queue_depth( int $by ): void {
		self::set_queue_depth( self::queue_depth() + $by );
	}

	/**
	 * Set the hint.
	 *
	 * @param int $depth New depth.
	 */
	public static function set_queue_depth( int $depth ): void {
		update_option( self::OPTION_QUEUE_DEPTH, max( 0, $depth ), true );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Recursive merge where the stored value wins but unknown keys survive.
	 *
	 * @param array<string, mixed> $defaults Defaults.
	 * @param array<string, mixed> $stored   Stored values.
	 * @return array<string, mixed>
	 */
	private static function merge_deep( array $defaults, array $stored ): array {
		foreach ( $stored as $key => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) ) {
				$defaults[ $key ] = self::merge_deep( $defaults[ $key ], $value );
				continue;
			}
			$defaults[ $key ] = $value;
		}

		return $defaults;
	}

	/**
	 * Drop the in-request cache. Used by tests and after a save.
	 */
	public static function flush(): void {
		self::$settings_cache    = null;
		self::$custom_urls_cache = null;
	}
}
