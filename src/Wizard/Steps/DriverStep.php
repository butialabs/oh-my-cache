<?php
/**
 * Step 1: which cache runs on this server.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Wizard\Steps;

use OhMyCache\Cache\NginxFastCgiDriver;
use OhMyCache\Cache\Redis\Connection;
use OhMyCache\Cache\RedisDriver;
use OhMyCache\Support\Migrator;
use OhMyCache\Support\Options;
use OhMyCache\Wizard\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Pick NGINX or Redis, then prove it actually works.
 *
 * The two are mutually exclusive on purpose. A site has one page cache in front of PHP, and
 * offering both at once mostly produces installs where one of them is configured wrong and
 * silently clears nothing, which is the failure this plugin exists to make impossible.
 */
final class DriverStep extends AbstractStep {

	/** Where an nginx cache usually lives. */
	private const CANDIDATES = [
		'/var/run/nginx-cache',
		'/var/cache/nginx',
		'/var/run/nginx-fastcgi-cache',
	];

	public function id(): string {
		return 'driver';
	}

	public function title(): string {
		return __( 'Driver', 'oh-my-cache' );
	}

	public function is_complete(): bool {
		return (bool) Options::cf_state( 'driver_tested', false );
	}

	public function can_skip(): bool {
		// A site with no local cache is a real setup, so "none" is a valid answer, not a skip.
		return false;
	}

	public function render(): void {
		$current = Options::local_driver();

		$this->paragraph(
			__( 'Your server probably keeps a copy of each page so it does not have to build it again. Tell us which one you use, and we will clear it whenever something changes.', 'oh-my-cache' )
		);

		$this->table_open();

		echo '<tr><th scope="row">' . esc_html__( 'Cache on this server', 'oh-my-cache' ) . '</th><td>';
		printf( '<fieldset><legend class="screen-reader-text">%s</legend>', esc_html__( 'Cache on this server', 'oh-my-cache' ) );

		$this->choice( 'nginx', __( 'NGINX', 'oh-my-cache' ), __( 'The most common setup on a VPS or managed WordPress host.', 'oh-my-cache' ), $current );
		$this->choice( 'redis', __( 'Redis', 'oh-my-cache' ), __( 'Pages stored in Redis, usually alongside nginx srcache.', 'oh-my-cache' ), $current );
		$this->choice( 'none', __( 'Neither', 'oh-my-cache' ), __( 'Nothing caches pages here. We will only clear your CDN.', 'oh-my-cache' ), $current );

		echo '</fieldset></td></tr>';
		$this->table_close();

		$this->render_nginx_block();
		$this->render_redis_block();

		$this->test_summary(
			[
				__( 'NGINX: the cache folder exists and PHP can write to it', 'oh-my-cache' ),
				__( 'Redis: the server answers, and its key prefix will not collide with your object cache', 'oh-my-cache' ),
			]
		);

		$this->render_import();
	}

	/**
	 * @param array<string, mixed> $input Form input.
	 */
	public function apply( array $input ): StepResult {
		$choice = isset( $input['local_driver'] ) ? sanitize_key( (string) $input['local_driver'] ) : 'none';
		$choice = in_array( $choice, [ 'none', 'nginx', 'redis' ], true ) ? $choice : 'none';

		$settings = Options::all();

		$settings['enabled']                     = true;
		$settings['drivers']['nginx']['enabled'] = ( 'nginx' === $choice );
		$settings['drivers']['redis']['enabled'] = ( 'redis' === $choice );

		if ( isset( $input['cache_path'] ) ) {
			$settings['drivers']['nginx']['cache_path'] = rtrim( (string) $input['cache_path'], '/\\' );
		}

		if ( isset( $input['redis_host'] ) && '' !== $input['redis_host'] ) {
			$settings['drivers']['redis']['host'] = sanitize_text_field( (string) $input['redis_host'] );
		}

		if ( isset( $input['redis_port'] ) && '' !== $input['redis_port'] ) {
			$settings['drivers']['redis']['port'] = max( 1, min( 65535, (int) $input['redis_port'] ) );
		}

		if ( isset( $input['redis_prefix'] ) && '' !== $input['redis_prefix'] ) {
			$settings['drivers']['redis']['prefix'] = sanitize_text_field( (string) $input['redis_prefix'] );
		}

		Options::save( $settings );
		Options::flush();

		if ( ! empty( $input['import_nginx_helper'] ) ) {
			Migrator::import_nginx_helper();
			Options::flush();
		}

		return $this->test( $choice );
	}

	public function revert(): StepResult {
		$settings                                = Options::all();
		$settings['drivers']['nginx']['enabled'] = false;
		$settings['drivers']['redis']['enabled'] = false;
		Options::save( $settings );

		Options::set_cf_state( [ 'driver_tested' => false ] );

		return StepResult::success( __( 'Local cache switched off.', 'oh-my-cache' ) );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Prove the chosen driver actually works before letting anyone past.
	 *
	 * @param string $choice nginx, redis or none.
	 */
	private function test( string $choice ): StepResult {
		if ( 'none' === $choice ) {
			Options::set_cf_state( [ 'driver_tested' => true ] );

			return StepResult::success( __( 'No local cache to clear. We will look after your CDN instead.', 'oh-my-cache' ) );
		}

		if ( 'nginx' === $choice ) {
			$driver       = new NginxFastCgiDriver();
			$availability = $driver->availability();

			if ( ! $availability->ok ) {
				return StepResult::failure( $availability->reason . ( '' === $availability->hint ? '' : ' ' . $availability->hint ) );
			}

			Options::set_cf_state( [ 'driver_tested' => true ] );

			return StepResult::success(
				sprintf(
					/* translators: %s: filesystem path. */
					__( 'NGINX cache found at %s and it is writable.', 'oh-my-cache' ),
					$driver->cache_path()
				)
			);
		}

		$driver       = new RedisDriver();
		$availability = $driver->availability();

		if ( ! $availability->ok ) {
			return StepResult::failure( $availability->reason . ( '' === $availability->hint ? '' : ' ' . $availability->hint ) );
		}

		$connection = $driver->connection();

		if ( ! $connection->ping() ) {
			return StepResult::failure(
				sprintf(
					/* translators: %s: connection error. */
					__( 'Redis did not answer: %s', 'oh-my-cache' ),
					(string) $connection->error()
				)
			);
		}

		Options::set_cf_state( [ 'driver_tested' => true ] );

		return StepResult::success(
			sprintf(
				/* translators: 1: Redis server version, 2: key prefix. */
				__( 'Connected to Redis %1$s. Clearing will only touch keys starting with "%2$s".', 'oh-my-cache' ),
				$connection->server_version(),
				$driver->prefix()
			)
		);
	}

	/**
	 * @param string $value   Choice value.
	 * @param string $label   Label.
	 * @param string $help    One-line explanation.
	 * @param string $current Currently selected value.
	 */
	private function choice( string $value, string $label, string $help, string $current ): void {
		printf(
			'<label><input type="radio" name="local_driver" value="%s" %s class="omc-driver-choice" /> <strong>%s</strong></label><p class="description omc-choice-help">%s</p>',
			esc_attr( $value ),
			checked( $current, $value, false ),
			esc_html( $label ),
			esc_html( $help )
		);
	}

	private function render_nginx_block(): void {
		printf( '<div class="omc-driver-block" data-omc-driver-block="nginx"%s>', 'nginx' === Options::local_driver() ? '' : ' hidden' );
		printf( '<h3>%s</h3>', esc_html__( 'NGINX', 'oh-my-cache' ) );

		$detected = $this->detect_path();

		$this->table_open();

		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="cache_path" value="%s" class="regular-text" placeholder="%s" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Cache folder', 'oh-my-cache' ),
			esc_attr( $detected ),
			esc_attr( '/var/run/nginx-cache' ),
			esc_html(
				'' === $detected
					? __( 'We could not find one in the usual places. Your host can tell you where it is.', 'oh-my-cache' )
					: __( 'Found automatically. We will confirm PHP can write to it when you continue.', 'oh-my-cache' )
			)
		);

		$this->table_close();
		echo '</div>';
	}

	private function render_redis_block(): void {
		printf( '<div class="omc-driver-block" data-omc-driver-block="redis"%s>', 'redis' === Options::local_driver() ? '' : ' hidden' );
		printf( '<h3>%s</h3>', esc_html__( 'Redis', 'oh-my-cache' ) );

		if ( ! Connection::extension_ok() ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: installed version or "not installed", 2: minimum version. */
						__( 'phpredis %1$s. Redis needs version %2$s or newer, and this plugin does not bundle a slower pure-PHP replacement. Ask your host to install or update it.', 'oh-my-cache' ),
						Connection::extension_version() ?: __( 'is not installed', 'oh-my-cache' ),
						Connection::MIN_EXTENSION
					)
				)
			);
		}

		$this->table_open();

		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="redis_host" value="%s" class="regular-text" placeholder="127.0.0.1" /></td></tr>',
			esc_html__( 'Server address', 'oh-my-cache' ),
			esc_attr( (string) $this->setting( 'drivers.redis.host', '127.0.0.1' ) )
		);

		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="redis_port" value="%s" class="small-text" placeholder="6379" /></td></tr>',
			esc_html__( 'Port', 'oh-my-cache' ),
			esc_attr( (string) $this->setting( 'drivers.redis.port', 6379 ) )
		);

		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="redis_prefix" value="%s" class="regular-text" placeholder="nginx-cache:" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Key prefix', 'oh-my-cache' ),
			esc_attr( (string) $this->setting( 'drivers.redis.prefix', 'nginx-cache:' ) ),
			esc_html__( 'Identifies your cached pages. We check it cannot collide with your object cache, so clearing pages never wipes anything else.', 'oh-my-cache' )
		);

		$this->table_close();

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'A username and password, if your Redis needs them, can be added afterwards under Settings, Driver.', 'oh-my-cache' )
		);

		echo '</div>';
	}

	/**
	 * Offer to bring settings across from the plugin being replaced.
	 */
	private function render_import(): void {
		if ( null === Migrator::nginx_helper_options() ) {
			return;
		}

		printf(
			'<p><label><input type="checkbox" name="import_nginx_helper" value="1" checked /> %s</label></p>',
			esc_html__( 'Bring over the settings from Nginx Helper. Its own settings are left untouched, so you can switch back.', 'oh-my-cache' )
		);
	}

	/**
	 * Find a cache folder we can actually write to.
	 *
	 * Writability is probed with a real file, not is_writable(), because nginx typically owns
	 * these files as www-data while PHP-FPM runs as somebody else. That mismatch is the single
	 * most common reason file-based clearing silently does nothing.
	 */
	private function detect_path(): string {
		$configured = (string) $this->setting( 'drivers.nginx.cache_path', '' );

		if ( '' !== $configured ) {
			return $configured;
		}

		$external = Options::external( 'nginx_path', [ 'RT_WP_NGINX_HELPER_CACHE_PATH' ] );

		if ( null !== $external && '' !== $external ) {
			return $external;
		}

		foreach ( self::CANDIDATES as $candidate ) {
			if ( is_dir( $candidate ) && $this->can_write( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * @param string $dir Directory.
	 */
	private function can_write( string $dir ): bool {
		$probe = $dir . '/.omc-probe-' . wp_generate_password( 8, false, false );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === @file_put_contents( $probe, 'x' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $probe );

		return true;
	}
}
