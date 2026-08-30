<?php
/**
 * phpredis wrapper.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cache\Redis;

use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Isolates the phpredis extension so nothing else in the plugin knows its API.
 *
 * Targets phpredis 6.x, developed against 6.3.0. The extension varies wildly between hosts and
 * calling a constant that does not exist is a fatal error rather than an exception, so every
 * option is applied behind a defined() check and the whole driver refuses to run below 6.0
 * with a readable reason instead of taking the site down.
 */
final class Connection {

	public const MIN_EXTENSION = '6.0';
	public const TESTED_AGAINST = '6.3.0';

	private ?\Redis $redis = null;

	private ?string $error = null;

	private ?bool $supports_unlink = null;

	/**
	 * Whether the extension is present and new enough.
	 */
	public static function extension_ok(): bool {
		return extension_loaded( 'redis' )
			&& version_compare( (string) phpversion( 'redis' ), self::MIN_EXTENSION, '>=' );
	}

	/**
	 * Installed extension version, or empty string.
	 */
	public static function extension_version(): string {
		return extension_loaded( 'redis' ) ? (string) phpversion( 'redis' ) : '';
	}

	/**
	 * Last connection or command error.
	 */
	public function error(): ?string {
		return $this->error;
	}

	/**
	 * Connect, or return the live handle.
	 *
	 * @return \Redis|null Null when the connection failed; see error().
	 */
	public function connect(): ?\Redis {
		if ( $this->redis instanceof \Redis ) {
			return $this->redis;
		}

		if ( ! self::extension_ok() ) {
			$this->error = sprintf(
				/* translators: 1: installed phpredis version or "none", 2: minimum version. */
				__( 'phpredis %1$s is installed; %2$s or newer is required.', 'oh-my-cache' ),
				self::extension_version() ?: __( 'none', 'oh-my-cache' ),
				self::MIN_EXTENSION
			);

			return null;
		}

		$redis = new \Redis();

		try {
			$socket = (string) $this->setting( 'socket', '', 'redis_socket' );

			if ( '' !== $socket ) {
				$connected = $redis->connect( $socket );
			} else {
				$host = (string) $this->setting( 'host', '127.0.0.1', 'redis_host' );
				$port = (int) $this->setting( 'port', 6379, 'redis_port' );

				// phpredis speaks TLS through a scheme prefix on the host.
				if ( Options::flag( 'drivers.redis.tls', false ) && ! str_contains( $host, '://' ) ) {
					$host = 'tls://' . $host;
				}

				$connected = $redis->connect(
					$host,
					$port,
					(float) Options::get( 'drivers.redis.connect_timeout', 1.0 ),
					null,
					(int) Options::get( 'drivers.redis.retry_interval', 100 ),
					(float) Options::get( 'drivers.redis.read_timeout', 2.0 ),
					$this->context()
				);
			}

			if ( ! $connected ) {
				$this->error = __( 'Redis refused the connection.', 'oh-my-cache' );

				return null;
			}

			$this->apply_options( $redis );

			$database = (int) $this->setting( 'database', 0, 'redis_db' );
			if ( $database > 0 ) {
				$redis->select( $database );
			}
		} catch ( \Throwable $e ) {
			$this->error = $e->getMessage();

			return null;
		}

		$this->redis = $redis;

		return $this->redis;
	}

	/**
	 * Connection context: ACL credentials, and room for TLS stream options.
	 *
	 * Redis 6 ACL auth belongs here rather than as a bare AUTH after connecting, which is what
	 * the 7-argument connect() signature exists for.
	 *
	 * @return array<string, mixed>
	 */
	private function context(): array {
		$context = [];

		$user     = Options::secret( 'redis_username' );
		$password = Options::secret( 'redis_password' );

		if ( '' !== $password ) {
			$context['auth'] = '' !== $user ? [ $user, $password ] : [ $password ];
		}

		/**
		 * Filters the phpredis connection context, for TLS stream options and the like.
		 *
		 * @param array<string, mixed> $context Connection context.
		 */
		return (array) apply_filters( 'oh_my_cache_redis_context', $context );
	}

	/**
	 * Apply the resilience and behaviour options phpredis 6.x offers.
	 *
	 * Every one is guarded: a 6.0 that predates an option degrades instead of fataling.
	 *
	 * @param \Redis $redis Live handle.
	 */
	private function apply_options( \Redis $redis ): void {
		/*
		 * SCAN_RETRY makes scan() keep going when the server returns an empty batch with a
		 * non-zero cursor, which is normal and which naive loops mistake for "done".
		 */
		if ( defined( '\Redis::OPT_SCAN' ) && defined( '\Redis::SCAN_RETRY' ) ) {
			$redis->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
		}

		/*
		 * Let the extension absorb a transient network hiccup inside a single attempt. Anything
		 * that escapes this becomes a failed PurgeResult and goes to the plugin queue, which is
		 * where longer-horizon retries belong.
		 */
		if ( defined( '\Redis::OPT_MAX_RETRIES' ) ) {
			$redis->setOption( \Redis::OPT_MAX_RETRIES, max( 0, (int) Options::get( 'drivers.redis.max_retries', 2 ) ) );
		}

		if ( defined( '\Redis::OPT_BACKOFF_ALGORITHM' ) && defined( '\Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER' ) ) {
			$redis->setOption( \Redis::OPT_BACKOFF_ALGORITHM, \Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER );
		}

		/*
		 * OPT_PREFIX is deliberately left off. The cache keys are written by nginx, not by us;
		 * letting the extension silently prefix everything underneath is how you end up purging
		 * a key that does not exist while reporting success. The prefix goes into the key
		 * string explicitly, in RedisDriver.
		 */
	}

	/**
	 * Whether the server supports UNLINK (Redis 4.0+), detected once.
	 */
	public function supports_unlink(): bool {
		if ( null !== $this->supports_unlink ) {
			return $this->supports_unlink;
		}

		$this->supports_unlink = version_compare( $this->server_version(), '4.0', '>=' );

		return $this->supports_unlink;
	}

	/**
	 * Redis server version, or "0" when unknown.
	 */
	public function server_version(): string {
		$redis = $this->connect();

		if ( ! $redis instanceof \Redis ) {
			return '0';
		}

		try {
			$info = $redis->info( 'server' );
		} catch ( \Throwable $e ) {
			return '0';
		}

		return is_array( $info ) && isset( $info['redis_version'] )
			? (string) $info['redis_version']
			: '0';
	}

	/**
	 * Round trip check for the settings screen and the doctor.
	 */
	public function ping(): bool {
		$redis = $this->connect();

		if ( ! $redis instanceof \Redis ) {
			return false;
		}

		try {
			return (bool) $redis->ping();
		} catch ( \Throwable $e ) {
			$this->error = $e->getMessage();

			return false;
		}
	}

	/**
	 * Which database index we are talking to.
	 */
	public function database(): int {
		return (int) $this->setting( 'database', 0, 'redis_db' );
	}

	/**
	 * Setting value honouring env and constants, with the legacy Nginx Helper names.
	 *
	 * @param string $key           Setting key under drivers.redis.
	 * @param mixed  $default_value Fallback.
	 * @param string $external_key  Env/constant suffix.
	 * @return mixed
	 */
	private function setting( string $key, mixed $default_value, string $external_key ): mixed {
		$legacy = [
			'redis_host'   => 'RT_WP_NGINX_HELPER_REDIS_HOSTNAME',
			'redis_port'   => 'RT_WP_NGINX_HELPER_REDIS_PORT',
			'redis_socket' => 'RT_WP_NGINX_HELPER_REDIS_UNIX_SOCKET',
			'redis_db'     => 'RT_WP_NGINX_HELPER_REDIS_DATABASE',
			'redis_prefix' => 'RT_WP_NGINX_HELPER_REDIS_PREFIX',
		];

		$external = Options::external(
			$external_key,
			isset( $legacy[ $external_key ] ) ? [ $legacy[ $external_key ] ] : []
		);

		if ( null !== $external && '' !== $external ) {
			return $external;
		}

		return Options::get( 'drivers.redis.' . $key, $default_value );
	}

	/**
	 * Close the connection.
	 */
	public function close(): void {
		if ( $this->redis instanceof \Redis ) {
			try {
				$this->redis->close();
			} catch ( \Throwable $e ) {
				// Nothing useful to do while tearing down.
				unset( $e );
			}
		}

		$this->redis = null;
	}
}
