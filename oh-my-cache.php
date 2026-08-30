<?php
/**
 * Plugin Name:       Oh My Cache!
 * Plugin URI:        https://github.com/butialabs/oh-my-cache
 * Description:       Clears your NGINX or Redis page cache and then Cloudflare and retries anything that fails instead of losing it.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Butiá Labs
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       oh-my-cache
 * Domain Path:       /languages
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache;

defined( 'ABSPATH' ) || exit;

const VERSION    = '0.1.0';
const DB_VERSION = 1;
const MIN_PHP    = '8.1';
const MIN_WP     = '6.2';

define( 'OhMyCache\PLUGIN_FILE', __FILE__ );
define( 'OhMyCache\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OhMyCache\PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OhMyCache\PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Runtime version guard.
 *
 * The `Requires PHP` / `Requires at least` headers are the real barrier: WordPress 5.5+
 * refuses activation when they are not met. This is only a belt-and-braces `return` for an
 * install that got past that somehow (a manual copy, a PHP downgrade after activation).
 *
 * We deliberately do NOT call deactivate_plugins() here: it lives in
 * wp-admin/includes/plugin.php and does not exist on a front-end request, which is exactly
 * where this guard would fire.
 */
if ( version_compare( PHP_VERSION, MIN_PHP, '<' ) || version_compare( get_bloginfo( 'version' ), MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: required WordPress version, 3: running PHP version, 4: running WordPress version */
						__( 'Oh My Cache! requires PHP %1$s and WordPress %2$s or newer. This site runs PHP %3$s and WordPress %4$s, so the plugin is idle.', 'oh-my-cache' ),
						MIN_PHP,
						MIN_WP,
						PHP_VERSION,
						get_bloginfo( 'version' )
					)
				)
			);
		}
	);
	return;
}

/*
 * Autoloading. Prefer Composer's optimised map when the plugin was built with it; fall back
 * to a plain PSR-4 loader so a git checkout without vendor/ still runs.
 */
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = __NAMESPACE__ . '\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
			$path     = __DIR__ . '/src/' . $relative . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

/*
 * Public API: declarations only. No hooks, no option reads, no translatable strings.
 * Loading it here means oh_my_cache_purge_*() exists for anything running from
 * plugins_loaded onward, including plugins that load before this one finishes booting.
 */
require_once __DIR__ . '/api.php';

/*
 * Lifecycle hooks must live at the top level of the main file, never inside another hook,
 * or they never fire.
 */
register_activation_hook( __FILE__, [ Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Deactivator::class, 'deactivate' ] );

Plugin::boot();
