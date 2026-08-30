<?php
/**
 * NGINX FastCGI / proxy cache driver.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cache;

use OhMyCache\Support\Options;
use OhMyCache\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Purges the on-disk nginx cache, either by deleting the cache file or by asking nginx to do
 * it through ngx_cache_purge.
 */
final class NginxFastCgiDriver extends AbstractDriver {

	public function id(): string {
		return 'nginx';
	}

	public function label(): string {
		return __( 'NGINX FastCGI cache', 'oh-my-cache' );
	}

	/**
	 * Only the HTTP mode crosses the network; unlink is a local filesystem call.
	 */
	public function is_remote(): bool {
		return 'http' === $this->method();
	}

	public function inline_timeout(): float {
		// Filesystem deletes are effectively free, so they always run inline.
		return 'http' === $this->method() ? 3.0 : 0.0;
	}

	public function availability(): Availability {
		if ( 'http' === $this->method() ) {
			return Availability::ok();
		}

		$path = $this->cache_path();

		if ( '' === $path ) {
			return Availability::unavailable(
				__( 'No NGINX cache directory is configured.', 'oh-my-cache' ),
				__( 'Set it in Settings, or define OH_MY_CACHE_NGINX_PATH.', 'oh-my-cache' )
			);
		}

		if ( ! is_dir( $path ) ) {
			return Availability::unavailable(
				sprintf(
					/* translators: %s: filesystem path. */
					__( 'The NGINX cache directory %s does not exist.', 'oh-my-cache' ),
					$path
				)
			);
		}

		/*
		 * Direct filesystem calls throughout this driver, not WP_Filesystem, and deliberately so.
		 * WP_Filesystem can resolve to the FTP or SSH transport and then prompts for credentials,
		 * which is impossible from a purge running during a post save or a cron tick. It also
		 * targets paths inside the WordPress install, whereas this cache lives somewhere like
		 * /var/run owned by the nginx user. Reading and deleting those files directly, and
		 * reporting honestly when permissions do not allow it, is the only workable approach.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( ! is_writable( $path ) ) {
			/*
			 * This is the number one support issue with file-based purging: nginx writes the
			 * cache as its own user, PHP-FPM usually runs as another, and the delete silently
			 * fails. Saying so plainly beats a queue full of mysterious failures.
			 */
			return Availability::unavailable(
				sprintf(
					/* translators: %s: filesystem path. */
					__( 'The NGINX cache directory %s is not writable by PHP.', 'oh-my-cache' ),
					$path
				),
				__( 'nginx usually owns these files as www-data while PHP-FPM runs as another user. Align the users, or switch this driver to HTTP purge mode.', 'oh-my-cache' )
			);
		}

		return Availability::ok();
	}

	/**
	 * Purge a set of URLs.
	 *
	 * @param array<int, string> $urls Absolute URLs.
	 */
	public function purge_urls( array $urls ): PurgeResult {
		$result = PurgeResult::make();

		foreach ( $urls as $url ) {
			if ( 'http' === $this->method() ) {
				$this->purge_over_http( $url, $result );
				continue;
			}

			$this->purge_by_unlink( $url, $result );
		}

		return $result;
	}

	/**
	 * Empty the whole cache directory.
	 */
	public function purge_all(): PurgeResult {
		$result = PurgeResult::make();
		$path   = $this->cache_path();

		$guard = $this->guard_path( $path );
		if ( null !== $guard ) {
			return PurgeResult::fatal( [], $guard, false );
		}

		$removed = $this->delete_tree( $path, false );

		/**
		 * Fires after the NGINX cache directory has been emptied.
		 *
		 * @param string $path    Cache directory.
		 * @param int    $removed Files deleted.
		 */
		do_action( 'oh_my_cache_nginx_purged_all', $path, $removed );

		return $result->note(
			sprintf(
				/* translators: %d: number of cache files removed. */
				_n( '%d cache file removed', '%d cache files removed', $removed, 'oh-my-cache' ),
				$removed
			)
		);
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Delete the cache file nginx would have written for this URL.
	 *
	 * @param string      $url    Absolute URL.
	 * @param PurgeResult $result Accumulator.
	 */
	private function purge_by_unlink( string $url, PurgeResult $result ): void {
		$file = $this->cache_file_for( $url );

		if ( '' === $file ) {
			$result->skip( $url, __( 'Could not derive a cache path for this URL.', 'oh-my-cache' ) );
			return;
		}

		/**
		 * Filters the cache file about to be deleted.
		 *
		 * @param string $file Absolute path.
		 * @param string $url  URL being purged.
		 */
		$file = (string) apply_filters( 'oh_my_cache_nginx_cache_file', $file, $url );

		if ( ! file_exists( $file ) ) {
			// Not cached. This is the normal case, not a failure.
			$result->skip( $url, __( 'Not cached.', 'oh-my-cache' ) );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( @unlink( $file ) ) {
			$result->succeed( $url );
			return;
		}

		$result->fail( $url, __( 'The cache file exists but PHP could not delete it (check ownership).', 'oh-my-cache' ) );
	}

	/**
	 * Ask nginx to purge, via the ngx_cache_purge module.
	 *
	 * @param string      $url    Absolute URL.
	 * @param PurgeResult $result Accumulator.
	 */
	private function purge_over_http( string $url, PurgeResult $result ): void {
		$target = $this->purge_url_for( $url );

		if ( '' === $target ) {
			$result->skip( $url, __( 'Could not build a purge URL.', 'oh-my-cache' ) );
			return;
		}

		/**
		 * Filters the purge endpoint before it is requested.
		 *
		 * @param string $target Purge URL.
		 * @param string $url    Original URL.
		 */
		$target = (string) apply_filters( 'oh_my_cache_nginx_purge_url', $target, $url );

		$response = wp_remote_get(
			$target,
			[
				'timeout'   => $this->request_timeout(),
				'blocking'  => true,
				'sslverify' => false,
				'headers'   => [ 'Host' => (string) wp_parse_url( $url, PHP_URL_HOST ) ],
			]
		);

		if ( is_wp_error( $response ) ) {
			// A timeout or refused connection is worth retrying.
			$result->fail( $url, $response->get_error_message() );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			$result->succeed( $url );
			return;
		}

		if ( 404 === $code ) {
			$result->skip( $url, __( 'Not cached.', 'oh-my-cache' ) );
			return;
		}

		if ( 403 === $code || 401 === $code ) {
			/*
			 * Either ngx_cache_purge is not compiled in, or this IP is not allowed to call it.
			 * Retrying six times will not change that, so fail fast and loudly.
			 */
			$result->fail( $url, __( 'nginx refused the purge request. The ngx_cache_purge module may be missing, or this server is not in its allow list.', 'oh-my-cache' ) )
				->not_retryable();
			return;
		}

		$result->fail(
			$url,
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'nginx answered HTTP %d.', 'oh-my-cache' ),
				$code
			)
		);
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Absolute path of the cache file for a URL.
	 *
	 * nginx hashes the cache key with md5 and files it under a directory tree described by the
	 * `levels` parameter: levels=1:2 means one character from the end, then the two before
	 * that. Both the template and the levels are configurable here because both are
	 * configurable in nginx.
	 *
	 * @param string $url Absolute URL.
	 */
	public function cache_file_for( string $url ): string {
		$path = $this->cache_path();
		if ( '' === $path ) {
			return '';
		}

		$key = Url::cache_key( $url, $this->cache_key_template() );
		if ( '' === $key ) {
			return '';
		}

		$hash   = md5( $key );
		$levels = $this->levels();

		$segments = [];
		$offset   = 0;

		foreach ( $levels as $length ) {
			$offset    += $length;
			$segments[] = substr( $hash, -$offset, $length );
		}

		return rtrim( $path, '/\\' ) . '/' . ( $segments ? implode( '/', $segments ) . '/' : '' ) . $hash;
	}

	/**
	 * Parsed `levels` setting, e.g. "1:2" to [1, 2].
	 *
	 * @return array<int, int>
	 */
	private function levels(): array {
		$raw = (string) $this->setting( 'levels', '1:2' );

		$levels = array_values(
			array_filter(
				array_map( 'intval', explode( ':', $raw ) ),
				static fn ( int $n ): bool => $n > 0 && $n <= 2
			)
		);

		return $levels ?: [ 1, 2 ];
	}

	/**
	 * The purge endpoint for a URL, e.g. https://example.com/purge/some/path/.
	 *
	 * @param string $url Absolute URL.
	 */
	private function purge_url_for( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$prefix = '/' . trim( (string) $this->setting( 'purge_prefix', '/purge' ), '/' );
		$path   = (string) ( $parts['path'] ?? '/' );
		$query  = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

		return ( $parts['scheme'] ?? Url::site_scheme() ) . '://' . $parts['host']
			. ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' )
			. $prefix . $path . $query;
	}

	private function method(): string {
		$method = (string) $this->setting( 'method', 'unlink' );

		return 'http' === $method ? 'http' : 'unlink';
	}

	/**
	 * Configured cache directory, honouring env, our constant and the legacy Nginx Helper one.
	 */
	public function cache_path(): string {
		$external = Options::external( 'nginx_path', [ 'RT_WP_NGINX_HELPER_CACHE_PATH' ] );

		$path = null !== $external && '' !== $external
			? $external
			: (string) $this->setting( 'cache_path', '' );

		return '' === $path ? '' : rtrim( $path, '/\\' );
	}

	private function cache_key_template(): string {
		$template = (string) $this->setting( 'cache_key', '$scheme$request_method$host$request_uri' );

		return '' === $template ? '$scheme$request_method$host$request_uri' : $template;
	}

	/**
	 * Inline attempts get a short leash; queued ones can afford to wait.
	 */
	private function request_timeout(): float {
		return wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ? 8.0 : 3.0;
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Refuse to recursively delete anything that is not obviously a cache directory.
	 *
	 * Nginx Helper's unlink_recursive() has no such guard, so a mistyped path there is data
	 * loss with no confirmation step.
	 *
	 * @param string $path Candidate directory.
	 * @return string|null Reason to refuse, or null when the path is safe.
	 */
	private function guard_path( string $path ): ?string {
		if ( '' === $path ) {
			return __( 'No cache directory is configured.', 'oh-my-cache' );
		}

		$real = realpath( $path );

		if ( false === $real ) {
			return sprintf(
				/* translators: %s: filesystem path. */
				__( 'The cache directory %s does not resolve to a real path.', 'oh-my-cache' ),
				$path
			);
		}

		$real = rtrim( str_replace( '\\', '/', $real ), '/' );

		if ( '' === $real || '/' === $real ) {
			return __( 'Refusing to empty the filesystem root.', 'oh-my-cache' );
		}

		$abspath = realpath( ABSPATH );
		if ( false !== $abspath ) {
			$abspath = rtrim( str_replace( '\\', '/', $abspath ), '/' );

			if ( $real === $abspath ) {
				return __( 'Refusing to empty the WordPress root.', 'oh-my-cache' );
			}

			// Refuse any ancestor of ABSPATH: emptying it would take WordPress with it.
			if ( str_starts_with( $abspath . '/', $real . '/' ) ) {
				return __( 'Refusing to empty a directory that contains WordPress.', 'oh-my-cache' );
			}
		}

		return null;
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $path   Directory.
	 * @param bool   $remove Whether to remove the directory itself.
	 * @return int Files deleted.
	 */
	private function delete_tree( string $path, bool $remove = true ): int {
		if ( ! is_dir( $path ) ) {
			return 0;
		}

		$deleted = 0;
		$entries = @scandir( $path );

		if ( false === $entries ) {
			return 0;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$full = $path . '/' . $entry;

			if ( is_dir( $full ) && ! is_link( $full ) ) {
				$deleted += $this->delete_tree( $full, true );
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( @unlink( $full ) ) {
				++$deleted;
			}
		}

		if ( $remove ) {
			// See the note in availability() on why this is not WP_Filesystem.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $path );
		}

		return $deleted;
	}
}
