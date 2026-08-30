<?php
/**
 * Import settings from the donor plugins.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Non-destructive import from Nginx Helper and App for Cloudflare.
 *
 * Source options are never deleted and the source plugins are never deactivated: if the import
 * gets something wrong, the operator can switch back. What does not get imported is as
 * deliberate as what does, and is listed in skipped_keys() so the reasoning is inspectable
 * rather than folded into the code.
 */
final class Migrator {

	public const NGINX_HELPER_OPTION = 'rt_wp_nginx_helper_options';
	public const APP_FOR_CF_OPTION   = 'app_for_cf';

	/**
	 * What is available to import, without importing it.
	 *
	 * @return array<string, bool>
	 */
	public static function detect(): array {
		$found = [
			'nginx-helper' => is_array( self::nginx_helper_options() ),
			'app-for-cf'   => is_array( get_option( self::APP_FOR_CF_OPTION, null ) ),
		];

		update_option( 'oh_my_cache_importable', $found, false );

		return $found;
	}

	/**
	 * Read Nginx Helper settings, network option first, as that plugin stores them there.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function nginx_helper_options(): ?array {
		$options = get_site_option( self::NGINX_HELPER_OPTION, null );

		if ( ! is_array( $options ) ) {
			$options = get_option( self::NGINX_HELPER_OPTION, null );
		}

		return is_array( $options ) ? $options : null;
	}

	/**
	 * Import from Nginx Helper.
	 *
	 * @param bool $dry_run Report the changes without saving them.
	 * @return array<string, mixed> The settings tree that would be, or was, saved.
	 */
	public static function import_nginx_helper( bool $dry_run = false ): array {
		$source = self::nginx_helper_options();

		if ( null === $source ) {
			return [];
		}

		$settings = Options::all();

		$settings['enabled'] = ! empty( $source['enable_purge'] );

		$method = (string) ( $source['cache_method'] ?? 'enable_fastcgi' );
		$settings['drivers']['nginx']['enabled'] = ( 'enable_fastcgi' === $method );
		$settings['drivers']['redis']['enabled'] = ( 'enable_redis' === $method );

		// get_request means the ngx_cache_purge HTTP endpoint; unlink_files deletes the file.
		$settings['drivers']['nginx']['method'] = ( 'get_request' === ( $source['purge_method'] ?? '' ) )
			? 'http'
			: 'unlink';

		$purge_map = [
			'purge_homepage_on_edit'       => 'homepage_on_edit',
			'purge_homepage_on_del'        => 'homepage_on_delete',
			'purge_archive_on_edit'        => 'archives_on_edit',
			'purge_archive_on_del'         => 'archives_on_delete',
			'purge_page_on_mod'            => 'post_on_edit',
			'purge_page_on_new_comment'    => 'on_new_comment',
			'purge_page_on_deleted_comment' => 'on_comment_status',
			'purge_feeds'                  => 'feeds',
		];

		foreach ( $purge_map as $from => $to ) {
			if ( array_key_exists( $from, $source ) ) {
				$settings['purge'][ $to ] = (bool) $source[ $from ];
			}
		}

		$redis_map = [
			'redis_hostname'    => 'host',
			'redis_port'        => 'port',
			'redis_prefix'      => 'prefix',
			'redis_database'    => 'database',
			'redis_unix_socket' => 'socket',
		];

		foreach ( $redis_map as $from => $to ) {
			if ( array_key_exists( $from, $source ) && '' !== $source[ $from ] ) {
				$settings['drivers']['redis'][ $to ] = $source[ $from ];
			}
		}

		if ( ! $dry_run ) {
			Options::save( $settings );

			Options::save_secrets(
				[
					'redis_username' => (string) ( $source['redis_username'] ?? '' ),
					'redis_password' => (string) ( $source['redis_password'] ?? '' ),
				]
			);

			// Custom purge URLs are unbounded, so they live outside the autoloaded option.
			if ( ! empty( $source['purge_url'] ) ) {
				update_option( 'oh_my_cache_custom_urls', (string) $source['purge_url'], false );
			}
		}

		return $settings;
	}

	/**
	 * Import from App for Cloudflare.
	 *
	 * @param bool $dry_run Report the changes without saving them.
	 * @return array<string, mixed>
	 */
	public static function import_app_for_cf( bool $dry_run = false ): array {
		$source = get_option( self::APP_FOR_CF_OPTION, null );

		if ( ! is_array( $source ) ) {
			return [];
		}

		$settings = Options::all();

		$settings['drivers']['cloudflare']['enabled'] = true;

		if ( isset( $source['cfPageCachingSeconds'] ) ) {
			$settings['edge']['ttl_seconds'] = (int) $source['cfPageCachingSeconds'];
		}

		if ( isset( $source['cfPurgeCacheOnAdminBar'] ) ) {
			$settings['admin_bar'] = (bool) $source['cfPurgeCacheOnAdminBar'];
		}

		if ( $dry_run ) {
			return $settings;
		}

		Options::save( $settings );

		$auth = is_array( $source['cloudflareAuth'] ?? null ) ? $source['cloudflareAuth'] : [];

		/*
		 * Only the token is carried over. A global API key in the old plugin's settings is
		 * deliberately left behind rather than imported: this plugin authenticates with scoped
		 * tokens only, and silently adopting an account-wide credential would be a downgrade in
		 * security performed on the user's behalf without asking.
		 *
		 * save_secrets() also drops any key the environment already supplies, so an install that
		 * has moved its token to an env var will not have it copied back into the database here.
		 */
		Options::save_secrets( [ 'cf_api_token' => (string) ( $auth['token'] ?? '' ) ] );

		Options::set_cf_state(
			array_filter(
				[
					'zone_id'    => (string) ( $source['cfZoneId'] ?? '' ),
					'zone_name'  => (string) ( $source['cfZone'] ?? '' ),
					'account_id' => (string) ( $source['cfAccountId'] ?? '' ),
				]
			)
		);

		return $settings;
	}

	/**
	 * Keys deliberately left behind, and why.
	 *
	 * @return array<string, string>
	 */
	public static function skipped_keys(): array {
		return [
			'enable_log'                     => 'Replaced by the queue screen, which records the outcome of every job.',
			'log_level'                      => 'Replaced by the queue screen.',
			'log_filesize'                   => 'Replaced by the queue screen.',
			'enable_map'                     => 'The multisite map.conf generator is out of scope.',
			'enable_stamp'                   => 'HTML timestamp comments are out of scope.',
			'is_cache_preloaded'             => 'A one-shot flag with no meaning here.',
			'preload_cache'                  => 'Preloading is a deliberate action here, from the dashboard or WP-CLI, not an automatic one.',
			'future_posts'                   => 'Written but never read by Nginx Helper; dead data.',
			'purge_amp_urls'                 => 'AMP variants are no longer purged; the option was removed rather than carried over.',
			'purge_archive_on_deleted_comment' => 'Saved but never read by Nginx Helper; dead data.',
			'cfTurnstile'                    => 'Turnstile is out of scope.',
			'cfR2Bucket'                     => 'R2 media offload is out of scope.',
			'cfLicenseKey'                   => 'No licensing here.',
			'cloudflareAuth.api_key'         => 'This plugin uses scoped API tokens only; a global API key authenticates as your whole account.',
			'cloudflareAuth.email'           => 'Only needed to pair with a global API key, which is not supported.',
		];
	}

	/**
	 * Whether either donor plugin is still active, which would mean double purging.
	 *
	 * @return array<int, string>
	 */
	public static function active_donors(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$donors = [
			'nginx-helper/nginx-helper.php' => 'Nginx Helper',
			'app-for-cf/app-for-cf.php'     => 'App for Cloudflare',
		];

		$active = [];

		foreach ( $donors as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$active[] = $name;
			}
		}

		return $active;
	}
}
