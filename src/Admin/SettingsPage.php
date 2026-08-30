<?php
/**
 * Settings screen.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Admin;

use OhMyCache\Cdn\Providers;
use OhMyCache\Cloudflare\Credentials;
use OhMyCache\Container;
use OhMyCache\Integrations\WooCommerce;
use OhMyCache\Purge\Sitemaps;
use OhMyCache\Support\Options;
use OhMyCache\Support\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * One form, one Save, several tabs.
 *
 * Every tab's fields stay in the DOM and the inactive ones are hidden. That is deliberate: an
 * unchecked checkbox posts nothing, so rendering only the active tab would make saving the Queue
 * tab quietly switch off every checkbox on the Purge tab. With all fields posted together, one
 * Save genuinely means one Save.
 */
final class SettingsPage {

	public const SECRETS_ACTION = 'oh_my_cache_secrets';

	public function __construct( private readonly Container $container ) {}

	/**
	 * Tab id to label, in display order.
	 *
	 * @return array<string, string>
	 */
	private function tabs(): array {
		return [
			'driver'     => __( 'Driver', 'oh-my-cache' ),
			'cdn'        => __( 'CDN', 'oh-my-cache' ),
			'purge'      => __( 'When to purge', 'oh-my-cache' ),
			'dispatch'   => __( 'Dispatch', 'oh-my-cache' ),
			'queue'      => __( 'Queue', 'oh-my-cache' ),
			'general'    => __( 'General', 'oh-my-cache' ),
		];
	}

	public function render(): void {
		$this->handle_secrets();

		$settings = Options::all();
		$active   = $this->active_tab();

		echo '<div class="wrap omc">';
		printf( '<h1>%s</h1>', esc_html__( 'Oh My Cache! settings', 'oh-my-cache' ) );

		settings_errors();

		$this->render_nav( $active );

		/*
		 * Credentials post here, not through options.php, so they never pass through the settings
		 * option at all. HTML forms cannot nest, so this one sits outside and the credential
		 * inputs reference it with the `form` attribute. That lets a password field sit visually
		 * inside the Redis block while submitting somewhere else entirely.
		 */
		echo '<form method="post" id="omc-secrets-form">';
		wp_nonce_field( self::SECRETS_ACTION );
		echo '<input type="hidden" name="omc_secrets" value="1" />';
		echo '</form>';

		echo '<form method="post" action="options.php" class="omc-settings-form">';
		settings_fields( 'oh_my_cache' );

		$this->panel( 'driver', $active, fn () => $this->tab_driver( $settings ) );
		$this->panel( 'cdn', $active, fn () => $this->tab_cdn( $settings ) );
		$this->panel( 'purge', $active, fn () => $this->tab_purge( $settings ) );
		$this->panel( 'dispatch', $active, fn () => $this->tab_dispatch( $settings ) );
		$this->panel( 'queue', $active, fn () => $this->tab_queue( $settings ) );
		$this->panel( 'general', $active, fn () => $this->tab_general( $settings ) );

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Saving applies to every tab at once.', 'oh-my-cache' )
		);

		submit_button();
		echo '</form>';

		$this->render_secrets_fields();

		echo '</div>';
	}

	/**
	 * Standard WordPress tab navigation.
	 *
	 * @param string $active Active tab id.
	 */
	private function render_nav( string $active ): void {
		printf(
			'<nav class="nav-tab-wrapper wp-clearfix" aria-label="%s">',
			esc_attr__( 'Settings sections', 'oh-my-cache' )
		);

		foreach ( $this->tabs() as $id => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s" data-omc-tab="%s">%s</a>',
				esc_url(
					add_query_arg(
						[
							'page' => Menu::SLUG_SETTINGS,
							'tab'  => $id,
						],
						admin_url( 'admin.php' )
					)
				),
				$id === $active ? ' nav-tab-active' : '',
				esc_attr( $id ),
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * Wrap one tab's fields in a panel that is hidden unless active.
	 *
	 * @param string   $id      Tab id.
	 * @param string   $active  Active tab id.
	 * @param callable $content Renders the fields.
	 */
	private function panel( string $id, string $active, callable $content ): void {
		printf(
			'<div class="omc-panel" data-omc-panel="%s"%s>',
			esc_attr( $id ),
			$id === $active ? '' : ' hidden'
		);

		$content();

		echo '</div>';
	}

	private function active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return array_key_exists( $tab, $this->tabs() ) ? $tab : 'driver';
	}

	/* --------------------------------------------------------------------- */
	/* Tabs                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function tab_driver( array $settings ): void {
		$current = Options::local_driver();

		printf( '<h2>%s</h2>', esc_html__( 'Local cache driver', 'oh-my-cache' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The page cache running on this server, in front of PHP.', 'oh-my-cache' )
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Driver', 'oh-my-cache' ) . '</th><td>';
		printf(
			'<fieldset><legend class="screen-reader-text">%s</legend>',
			esc_html__( 'Local cache driver', 'oh-my-cache' )
		);

		$choices = [
			'none'  => __( 'None. Only clear the CDN.', 'oh-my-cache' ),
			'nginx' => __( 'NGINX FastCGI or proxy cache', 'oh-my-cache' ),
			'redis' => __( 'Redis page cache', 'oh-my-cache' ),
		];

		foreach ( $choices as $value => $label ) {
			printf(
				'<label><input type="radio" name="%s" value="%s" %s class="omc-driver-choice" /> %s</label><br />',
				esc_attr( $this->field_name( 'local_driver' ) ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset></td></tr>';
		echo '</tbody></table>';

		/*
		 * Hidden server-side as well as by script. The correct block is then right on first
		 * paint, with no flash of the wrong driver's settings while JavaScript loads.
		 */
		printf(
			'<div class="omc-driver-block" data-omc-driver-block="nginx"%s>',
			'nginx' === $current ? '' : ' hidden'
		);
		printf( '<h3>%s</h3>', esc_html__( 'NGINX', 'oh-my-cache' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->select(
			'drivers[nginx][method]',
			__( 'How to clear it', 'oh-my-cache' ),
			[
				'unlink' => __( 'Delete the cache files directly (fastest)', 'oh-my-cache' ),
				'http'   => __( 'Ask nginx over HTTP (needs the ngx_cache_purge module)', 'oh-my-cache' ),
			],
			(string) $settings['drivers']['nginx']['method']
		);

		$this->text( 'drivers[nginx][cache_path]', __( 'Cache folder', 'oh-my-cache' ), (string) $settings['drivers']['nginx']['cache_path'], '/var/run/nginx-cache' );

		$this->text(
			'drivers[nginx][levels]',
			__( 'Cache levels', 'oh-my-cache' ),
			(string) $settings['drivers']['nginx']['levels'],
			'1:2',
			__( 'Must match the levels= value in your fastcgi_cache_path directive. If this is wrong, clearing deletes nothing and reports nothing.', 'oh-my-cache' )
		);

		$this->text(
			'drivers[nginx][cache_key]',
			__( 'Cache key', 'oh-my-cache' ),
			(string) $settings['drivers']['nginx']['cache_key'],
			'$scheme$request_method$host$request_uri',
			__( 'Must match your fastcgi_cache_key directive.', 'oh-my-cache' )
		);

		$this->text(
			'drivers[nginx][purge_prefix]',
			__( 'Purge path', 'oh-my-cache' ),
			(string) $settings['drivers']['nginx']['purge_prefix'],
			'/purge',
			__( 'Only used by the HTTP method.', 'oh-my-cache' )
		);

		$this->dispatch_select( 'drivers[nginx][dispatch]', (string) $settings['drivers']['nginx']['dispatch'] );
		$this->test_button( 'nginx' );

		echo '</tbody></table></div>';

		// Redis, with its credentials in the same block.
		printf(
			'<div class="omc-driver-block" data-omc-driver-block="redis"%s>',
			'redis' === $current ? '' : ' hidden'
		);
		printf( '<h3>%s</h3>', esc_html__( 'Redis', 'oh-my-cache' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->text( 'drivers[redis][host]', __( 'Host', 'oh-my-cache' ), (string) $settings['drivers']['redis']['host'], '127.0.0.1' );
		$this->text( 'drivers[redis][port]', __( 'Port', 'oh-my-cache' ), (string) $settings['drivers']['redis']['port'], '6379' );
		$this->text( 'drivers[redis][socket]', __( 'Unix socket', 'oh-my-cache' ), (string) $settings['drivers']['redis']['socket'], '/var/run/redis/redis.sock', __( 'Optional. Overrides host and port when set.', 'oh-my-cache' ) );
		$this->text( 'drivers[redis][database]', __( 'Database number', 'oh-my-cache' ), (string) $settings['drivers']['redis']['database'], '0' );

		$this->text(
			'drivers[redis][prefix]',
			__( 'Key prefix', 'oh-my-cache' ),
			(string) $settings['drivers']['redis']['prefix'],
			'nginx-cache:',
			__( 'Must differ from your object cache prefix. If they overlap, clearing the page cache would delete the object cache with it, and the driver refuses to run rather than let that happen.', 'oh-my-cache' )
		);

		$this->checkbox( 'drivers[redis][tls]', __( 'Connect over TLS', 'oh-my-cache' ), (bool) $settings['drivers']['redis']['tls'] );

		$this->secret_row( 'redis_username', __( 'Username', 'oh-my-cache' ) );
		$this->secret_row( 'redis_password', __( 'Password', 'oh-my-cache' ) );

		$this->dispatch_select( 'drivers[redis][dispatch]', (string) $settings['drivers']['redis']['dispatch'] );
		$this->test_button( 'redis' );

		echo '</tbody></table></div>';
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function tab_cdn( array $settings ): void {
		$provider = Providers::current();

		printf( '<h2>%s</h2>', esc_html__( 'CDN', 'oh-my-cache' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Once the local cache is clear, the copy your CDN holds at the edge is cleared too.', 'oh-my-cache' )
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Provider', 'oh-my-cache' ) . '</th><td>';
		printf(
			'<fieldset><legend class="screen-reader-text">%s</legend>',
			esc_html__( 'CDN provider', 'oh-my-cache' )
		);

		foreach ( Providers::all() as $id => $label ) {
			printf(
				'<label><input type="radio" name="%s" value="%s" %s class="omc-cdn-choice" /> %s</label><br />',
				esc_attr( $this->field_name( 'cdn[provider]' ) ),
				esc_attr( (string) $id ),
				checked( $provider, (string) $id, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset></td></tr>';

		/*
		 * Edge caching time lives out here, above the provider block, because it is not a
		 * Cloudflare setting. It drives the s-maxage header this site sends, which any CDN
		 * honours. Anything genuinely Cloudflare-specific goes inside the block below.
		 */
		$this->text(
			'edge[ttl_seconds]',
			__( 'Cache pages at the edge for', 'oh-my-cache' ),
			(string) $settings['edge']['ttl_seconds'],
			'0',
			__( 'Zero means your CDN never caches HTML. Raise it only after a test purge has succeeded: a page held at the edge while purging is broken stays stale for the whole time, and visitors cannot get past it.', 'oh-my-cache' )
		);

		echo '</tbody></table>';

		// Hidden server-side too, so the right block is there on first paint.
		printf(
			'<div class="omc-cdn-block" data-omc-cdn-block="cloudflare"%s>',
			'cloudflare' === $provider ? '' : ' hidden'
		);
		printf( '<h3>%s</h3>', esc_html__( 'Cloudflare', 'oh-my-cache' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox(
			'drivers[cloudflare][enabled]',
			__( 'Clear the Cloudflare cache', 'oh-my-cache' ),
			(bool) $settings['drivers']['cloudflare']['enabled']
		);

		$this->secret_row(
			'cf_api_token',
			__( 'API token', 'oh-my-cache' ),
			__( 'Scope it to this zone, with Cache Purge, Cache Rules and Zone Settings permissions. A global API key is never asked for: it covers your whole account and cannot be limited to one site.', 'oh-my-cache' )
		);

		$zone = Credentials::zone_name();

		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html__( 'Zone', 'oh-my-cache' ),
			'' === $zone
				? sprintf(
					'<em>%s</em> <a href="%s" class="button button-secondary">%s</a>',
					esc_html__( 'Not connected yet.', 'oh-my-cache' ),
					esc_url(
						add_query_arg(
							[
								'page' => Menu::SLUG_SETUP,
								'step' => 'cdn',
							],
							admin_url( 'admin.php' )
						)
					),
					esc_html__( 'Connect Cloudflare', 'oh-my-cache' )
				)
				: sprintf(
					'<code>%s</code> <span class="description">%s</span>',
					esc_html( $zone ),
					esc_html(
						sprintf(
							/* translators: %s: Cloudflare plan name. */
							__( '%s plan', 'oh-my-cache' ),
							(string) Options::cf_state( 'plan', 'unknown' )
						)
					)
				)
		);

		$this->dispatch_select( 'drivers[cloudflare][dispatch]', (string) $settings['drivers']['cloudflare']['dispatch'] );
		$this->test_button( 'cloudflare' );

		/*
		 * These two stay inside the Cloudflare block: one reads CF-Connecting-IP, the other
		 * exists to undo a redirect loop that Cloudflare Flexible SSL causes. Neither means
		 * anything to another CDN.
		 */
		$this->checkbox(
			'edge[true_client_ip]',
			__( 'Restore the visitor IP address', 'oh-my-cache' ),
			(bool) $settings['edge']['true_client_ip'],
			__( 'Without it, every visitor looks like a Cloudflare server. It only trusts requests that genuinely come from Cloudflare. The OH_MY_CACHE_TRUE_CLIENT_IP constant does the same job earlier, before other plugins read the address.', 'oh-my-cache' )
		);

		$this->checkbox(
			'edge[force_https_from_proto]',
			__( 'Trust X-Forwarded-Proto for HTTPS', 'oh-my-cache' ),
			(bool) $settings['edge']['force_https_from_proto'],
			__( 'Only turn this on to break a redirect loop caused by Cloudflare Flexible SSL. It is unsafe when another proxy in front also sets that header.', 'oh-my-cache' )
		);

		echo '</tbody></table></div>';
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function tab_purge( array $settings ): void {
		printf( '<h2>%s</h2>', esc_html__( 'When to purge', 'oh-my-cache' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Which changes clear the cache, and how far the clearing reaches.', 'oh-my-cache' )
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		$rows = [
			'post_on_edit'       => __( 'A post is published or edited', 'oh-my-cache' ),
			'homepage_on_edit'   => __( 'Also clear the homepage', 'oh-my-cache' ),
			'archives_on_edit'   => __( 'Also clear category, tag, date and author pages', 'oh-my-cache' ),
			'homepage_on_delete' => __( 'Clear the homepage when a post is deleted', 'oh-my-cache' ),
			'archives_on_delete' => __( 'Clear archives when a post is deleted', 'oh-my-cache' ),
			'on_new_comment'     => __( 'A comment is approved', 'oh-my-cache' ),
			'on_comment_status'  => __( 'A comment is unapproved, spammed or trashed', 'oh-my-cache' ),
			'on_attachment_edit' => __( 'A media file is edited', 'oh-my-cache' ),
			'on_term_change'     => __( 'A category or tag changes', 'oh-my-cache' ),
			'on_theme_switch'    => __( 'The theme or customiser changes, which clears everything', 'oh-my-cache' ),
			'on_menu_change'     => __( 'A menu changes, which clears everything', 'oh-my-cache' ),
			'feeds'              => __( 'Include RSS feeds', 'oh-my-cache' ),
		];

		foreach ( $rows as $key => $label ) {
			$this->checkbox( 'purge[' . $key . ']', $label, (bool) ( $settings['purge'][ $key ] ?? false ) );
		}

		if ( WooCommerce::is_active() ) {
			$this->checkbox(
				'purge[woocommerce]',
				__( 'Stock and price changes in WooCommerce', 'oh-my-cache' ),
				(bool) ( $settings['purge']['woocommerce'] ?? true ),
				__( 'WooCommerce writes stock and prices without saving the post, so the events above never see them. Leave this on or a sold-out product keeps showing as in stock.', 'oh-my-cache' )
			);
		}

		$sitemaps = new Sitemaps();

		$this->checkbox(
			'purge[sitemaps]',
			__( 'Include sitemaps', 'oh-my-cache' ),
			(bool) ( $settings['purge']['sitemaps'] ?? true ),
			Sitemaps::NONE === $sitemaps->provider()
				? __( 'Nothing here generates sitemaps, so none are cleared.', 'oh-my-cache' )
				: sprintf(
					/* translators: %s: name of the plugin generating sitemaps. */
					__( 'Sitemaps here come from %s. A cached one keeps listing the posts you had yesterday.', 'oh-my-cache' ),
					$sitemaps->provider_label()
				)
		);

		$this->text(
			'purge[pagination_depth]',
			__( 'Paged homepage depth', 'oh-my-cache' ),
			(string) $settings['purge']['pagination_depth'],
			'5',
			__( 'How many of /page/2/, /page/3/ and so on to clear alongside the homepage.', 'oh-my-cache' )
		);

		$this->text(
			'purge[max_urls]',
			__( 'Clear everything above', 'oh-my-cache' ),
			(string) $settings['purge']['max_urls'],
			'1000',
			__( 'When a single change would clear more URLs than this, clearing everything is faster and cheaper, so that is what happens.', 'oh-my-cache' )
		);

		echo '</tbody></table>';

		printf( '<h3>%s</h3>', esc_html__( 'Post types', 'oh-my-cache' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Which post types clear anything when they change. A type switched off still clears when the API or WP-CLI asks for it.', 'oh-my-cache' )
		);

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Clear on change', 'oh-my-cache' ) . '</th><td>';
		printf( '<fieldset><legend class="screen-reader-text">%s</legend>', esc_html__( 'Post types', 'oh-my-cache' ) );

		foreach ( self::purgeable_post_types() as $post_type ) {
			printf(
				'<label><input type="checkbox" name="%s" value="1" %s /> %s <code>%s</code></label><br />',
				esc_attr( $this->field_name( 'purge[post_types][' . $post_type->name . ']' ) ),
				checked( Options::post_type_enabled( $post_type->name ), true, false ),
				esc_html( $post_type->labels->name ?? $post_type->name ),
				esc_html( $post_type->name )
			);
		}

		echo '</fieldset></td></tr>';
		echo '</tbody></table>';

		printf( '<h3>%s</h3>', esc_html__( 'Always clear these too', 'oh-my-cache' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="omc-custom-urls">%s</label></th><td><textarea id="omc-custom-urls" name="%s" rows="5" class="large-text code" placeholder="%s">%s</textarea><p class="description">%s</p><p class="description">%s</p></td></tr>',
			esc_html__( 'Paths', 'oh-my-cache' ),
			esc_attr( $this->field_name( 'custom_urls' ) ),
			esc_attr( "/llms.txt\n/sitemap.xml" ),
			esc_textarea( Options::custom_urls_raw() ),
			esc_html__( 'One per line: a path such as /llms.txt, or a full URL. Cleared alongside every purge, which suits a file regenerated whenever content changes.', 'oh-my-cache' ),
			esc_html__( 'Wildcards are not accepted: they match nothing on NGINX or on Cloudflare below Enterprise, while looking like they worked.', 'oh-my-cache' )
		);

		echo '</tbody></table>';
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function tab_dispatch( array $settings ): void {
		printf( '<h2>%s</h2>', esc_html__( 'Dispatch', 'oh-my-cache' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Caches clear immediately by default. The queue is the safety net for whatever does not answer in time, not the normal path.', 'oh-my-cache' )
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->select(
			'dispatch[mode]',
			__( 'Mode', 'oh-my-cache' ),
			[
				'realtime' => __( 'Immediately, falling back to the queue', 'oh-my-cache' ),
				'queue'    => __( 'Always through the queue', 'oh-my-cache' ),
			],
			(string) $settings['dispatch']['mode']
		);

		$this->text(
			'dispatch[inline_budget]',
			__( 'Time budget', 'oh-my-cache' ),
			(string) $settings['dispatch']['inline_budget'],
			'8',
			__( 'Seconds a publish may spend clearing caches before the rest is queued.', 'oh-my-cache' )
		);

		$this->checkbox(
			'dispatch[inline_on_frontend]',
			__( 'Clear the CDN immediately on visitor actions too', 'oh-my-cache' ),
			(bool) $settings['dispatch']['inline_on_frontend'],
			__( 'Off by default, so a visitor posting a comment never waits on an API call. Your local cache always clears immediately either way, because it costs microseconds.', 'oh-my-cache' )
		);

		$this->text(
			'dispatch[inline_failure_threshold]',
			__( 'Failures before pausing a driver', 'oh-my-cache' ),
			(string) $settings['dispatch']['inline_failure_threshold'],
			'3'
		);

		$this->text(
			'dispatch[inline_cooldown]',
			__( 'Pause duration', 'oh-my-cache' ),
			(string) $settings['dispatch']['inline_cooldown'],
			'300',
			__( 'Seconds a repeatedly failing driver goes straight to the queue before being tried again.', 'oh-my-cache' )
		);

		echo '</tbody></table>';
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function tab_queue( array $settings ): void {
		printf( '<h2>%s</h2>', esc_html__( 'Queue', 'oh-my-cache' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->text( 'queue[max_attempts]', __( 'Attempts before giving up', 'oh-my-cache' ), (string) $settings['queue']['max_attempts'], '6' );
		$this->text( 'queue[batch_size]', __( 'Jobs per batch', 'oh-my-cache' ), (string) $settings['queue']['batch_size'], '10' );
		$this->text( 'queue[budget_seconds]', __( 'Worker time budget', 'oh-my-cache' ), (string) $settings['queue']['budget_seconds'], '20' );

		$this->select(
			'queue[worker_mode]',
			__( 'How the queue runs', 'oh-my-cache' ),
			[
				'cron'   => __( 'WP-Cron only', 'oh-my-cache' ),
				'inline' => __( 'At the end of each request', 'oh-my-cache' ),
				'both'   => __( 'Both', 'oh-my-cache' ),
			],
			(string) $settings['queue']['worker_mode'],
			__( 'If DISABLE_WP_CRON is set and you have no system cron entry, WP-Cron alone means queued work never runs.', 'oh-my-cache' )
		);

		$this->text( 'queue[retain_done_minutes]', __( 'Keep finished jobs for', 'oh-my-cache' ), (string) $settings['queue']['retain_done_minutes'], '60', __( 'Minutes.', 'oh-my-cache' ) );
		$this->text( 'queue[retain_dead_days]', __( 'Keep failed jobs for', 'oh-my-cache' ), (string) $settings['queue']['retain_dead_days'], '30', __( 'Days.', 'oh-my-cache' ) );

		echo '</tbody></table>';
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function tab_general( array $settings ): void {
		printf( '<h2>%s</h2>', esc_html__( 'General', 'oh-my-cache' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox( 'enabled', __( 'Clear caches automatically', 'oh-my-cache' ), (bool) $settings['enabled'] );
		$this->checkbox( 'admin_bar', __( 'Show the toolbar menu', 'oh-my-cache' ), (bool) $settings['admin_bar'] );

		$this->text(
			'preload[concurrency]',
			__( 'Preload concurrency', 'oh-my-cache' ),
			(string) $settings['preload']['concurrency'],
			'3',
			__( 'How many pages to request at once when warming the cache from your sitemap.', 'oh-my-cache' )
		);

		$this->checkbox(
			'delete_data_on_uninstall',
			__( 'Delete all data when the plugin is uninstalled', 'oh-my-cache' ),
			(bool) $settings['delete_data_on_uninstall'],
			__( 'Off by default. Cloudflare changes are never reverted on uninstall.', 'oh-my-cache' )
		);

		echo '</tbody></table>';

		printf( '<h3>%s</h3>', esc_html__( 'Setup', 'oh-my-cache' ) );
		printf(
			'<p><a href="%s" class="button button-secondary">%s</a></p>',
			esc_url( add_query_arg( 'page', Menu::SLUG_SETUP, admin_url( 'admin.php' ) ) ),
			esc_html__( 'Run the setup guide again', 'oh-my-cache' )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Sanitizer                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Sanitize the whole settings tree.
	 *
	 * @param mixed $input Raw posted value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$current  = Options::all();
		$defaults = Options::defaults();

		$out = $current;

		$out['enabled']                  = ! empty( $input['enabled'] );
		$out['admin_bar']                = ! empty( $input['admin_bar'] );
		$out['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		/*
		 * One radio decides which local driver runs, and both flags are written from it. Mutual
		 * exclusion is enforced here rather than trusting the interface to have offered only one
		 * option, because the interface is not the security boundary.
		 */
		$local = isset( $input['local_driver'] ) ? sanitize_key( (string) $input['local_driver'] ) : 'none';
		$local = in_array( $local, [ 'none', 'nginx', 'redis' ], true ) ? $local : 'none';

		$out['drivers']['nginx']['enabled'] = ( 'nginx' === $local );
		$out['drivers']['redis']['enabled'] = ( 'redis' === $local );

		// NGINX.
		$nginx = is_array( $input['drivers']['nginx'] ?? null ) ? $input['drivers']['nginx'] : [];

		$out['drivers']['nginx']['method']       = in_array( $nginx['method'] ?? '', [ 'unlink', 'http' ], true ) ? $nginx['method'] : 'unlink';
		$out['drivers']['nginx']['dispatch']     = self::dispatch_value( $nginx['dispatch'] ?? 'inherit' );
		$out['drivers']['nginx']['cache_path']   = self::path( (string) ( $nginx['cache_path'] ?? '' ) );
		$out['drivers']['nginx']['levels']       = self::levels( (string) ( $nginx['levels'] ?? '1:2' ) );
		$out['drivers']['nginx']['cache_key']    = sanitize_text_field( (string) ( $nginx['cache_key'] ?? $defaults['drivers']['nginx']['cache_key'] ) );
		$out['drivers']['nginx']['purge_prefix'] = '/' . trim( sanitize_text_field( (string) ( $nginx['purge_prefix'] ?? '/purge' ) ), '/' );

		// Redis.
		$redis = is_array( $input['drivers']['redis'] ?? null ) ? $input['drivers']['redis'] : [];

		$out['drivers']['redis']['dispatch']        = self::dispatch_value( $redis['dispatch'] ?? 'inherit' );
		$out['drivers']['redis']['host']            = sanitize_text_field( (string) ( $redis['host'] ?? '127.0.0.1' ) );
		$out['drivers']['redis']['port']            = self::int_in_range( $redis['port'] ?? 6379, 1, 65535, 6379 );
		$out['drivers']['redis']['socket']          = self::path( (string) ( $redis['socket'] ?? '' ) );
		$out['drivers']['redis']['database']        = self::int_in_range( $redis['database'] ?? 0, 0, 255, 0 );
		$out['drivers']['redis']['prefix']          = sanitize_text_field( (string) ( $redis['prefix'] ?? 'nginx-cache:' ) );
		$out['drivers']['redis']['tls']             = ! empty( $redis['tls'] );
		$out['drivers']['redis']['connect_timeout'] = self::float_in_range( $redis['connect_timeout'] ?? 1.0, 0.1, 30.0, 1.0 );
		$out['drivers']['redis']['read_timeout']    = self::float_in_range( $redis['read_timeout'] ?? 2.0, 0.1, 30.0, 2.0 );
		$out['drivers']['redis']['max_retries']     = self::int_in_range( $redis['max_retries'] ?? 2, 0, 10, 2 );
		$out['drivers']['redis']['scan_count']      = self::int_in_range( $redis['scan_count'] ?? 1000, 100, 10000, 1000 );

		// Cloudflare.
		$cf = is_array( $input['drivers']['cloudflare'] ?? null ) ? $input['drivers']['cloudflare'] : [];

		$out['drivers']['cloudflare']['enabled']  = ! empty( $cf['enabled'] );
		$out['drivers']['cloudflare']['dispatch'] = self::dispatch_value( $cf['dispatch'] ?? 'inherit' );

		// CDN provider.
		$cdn = is_array( $input['cdn'] ?? null ) ? $input['cdn'] : [];

		$out['cdn']['provider'] = Providers::sanitize( $cdn['provider'] ?? '' );

		// Purge triggers: booleans, except the two numbers.
		$purge = is_array( $input['purge'] ?? null ) ? $input['purge'] : [];

		foreach ( array_keys( $defaults['purge'] ) as $key ) {
			if ( in_array( $key, [ 'pagination_depth', 'max_urls', 'post_types_off' ], true ) ) {
				continue;
			}

			$out['purge'][ $key ] = ! empty( $purge[ $key ] );
		}

		/*
		 * The WooCommerce row is only rendered when the shop is active, and an unchecked box posts
		 * nothing. Without this, saving on a site with no shop would store "off" and disable stock
		 * purging for whoever installs one later. Only a screen showing a switch may change it.
		 */
		if ( ! WooCommerce::is_active() ) {
			$out['purge']['woocommerce'] = Options::flag( 'purge.woocommerce', true );
		}

		$out['purge']['post_types_off'] = self::disabled_post_types_from( $purge['post_types'] ?? [] );

		$out['purge']['pagination_depth'] = self::int_in_range( $purge['pagination_depth'] ?? 5, 0, 50, 5 );
		$out['purge']['max_urls']         = self::int_in_range( $purge['max_urls'] ?? 1000, 10, 100000, 1000 );

		// Dispatch.
		$dispatch = is_array( $input['dispatch'] ?? null ) ? $input['dispatch'] : [];

		$out['dispatch']['mode']                     = in_array( $dispatch['mode'] ?? '', [ 'realtime', 'queue' ], true ) ? $dispatch['mode'] : 'realtime';
		$out['dispatch']['inline_budget']            = self::float_in_range( $dispatch['inline_budget'] ?? 8.0, 0.5, 30.0, 8.0 );
		$out['dispatch']['inline_on_frontend']       = ! empty( $dispatch['inline_on_frontend'] );
		$out['dispatch']['inline_failure_threshold'] = self::int_in_range( $dispatch['inline_failure_threshold'] ?? 3, 1, 20, 3 );
		$out['dispatch']['inline_cooldown']          = self::int_in_range( $dispatch['inline_cooldown'] ?? 300, 30, 3600, 300 );

		// Queue.
		$queue = is_array( $input['queue'] ?? null ) ? $input['queue'] : [];

		$out['queue']['max_attempts']        = self::int_in_range( $queue['max_attempts'] ?? 6, 1, 20, 6 );
		$out['queue']['batch_size']          = self::int_in_range( $queue['batch_size'] ?? 10, 1, 200, 10 );
		$out['queue']['budget_seconds']      = self::int_in_range( $queue['budget_seconds'] ?? 20, 5, 120, 20 );
		$out['queue']['worker_mode']         = in_array( $queue['worker_mode'] ?? '', [ 'cron', 'inline', 'both' ], true ) ? $queue['worker_mode'] : 'cron';
		$out['queue']['retain_done_minutes'] = self::int_in_range( $queue['retain_done_minutes'] ?? 60, 1, 10080, 60 );
		$out['queue']['retain_dead_days']    = self::int_in_range( $queue['retain_dead_days'] ?? 30, 1, 365, 30 );

		// Edge.
		$edge = is_array( $input['edge'] ?? null ) ? $input['edge'] : [];

		$out['edge']['ttl_seconds']            = self::int_in_range( $edge['ttl_seconds'] ?? 0, 0, 31536000, 0 );
		$out['edge']['true_client_ip']         = ! empty( $edge['true_client_ip'] );
		$out['edge']['force_https_from_proto'] = ! empty( $edge['force_https_from_proto'] );

		// Preload.
		$preload = is_array( $input['preload'] ?? null ) ? $input['preload'] : [];

		$out['preload']['concurrency'] = self::int_in_range( $preload['concurrency'] ?? 3, 1, 20, 3 );

		/*
		 * The extra paths ride in on this form so one Save still saves everything, then go to
		 * their own option instead of the tree, which is read on every request.
		 */
		if ( isset( $input['custom_urls'] ) ) {
			$parsed = Options::sanitize_custom_urls( (string) $input['custom_urls'] );

			Options::save_custom_urls( $parsed['text'] );

			if ( $parsed['rejected'] ) {
				add_settings_error(
					'oh_my_cache',
					'omc_custom_urls_rejected',
					sprintf(
						/* translators: %s: comma separated list of the lines that were dropped. */
						__( 'Not saved: %s. A path cannot contain a wildcard.', 'oh-my-cache' ),
						implode( ', ', $parsed['rejected'] )
					),
					'warning'
				);
			}
		}

		unset( $out['custom_urls'] );

		/*
		 * Secrets never travel through this option. Even if a tampered form posts them under
		 * these keys, they are dropped here rather than persisted.
		 */
		foreach ( Options::SECRET_KEYS as $key ) {
			unset( $out[ $key ], $out['drivers']['redis'][ $key ] );
		}

		Options::flush();

		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Secrets                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Save credentials, honouring the environment.
	 */
	private function handle_secrets(): void {
		if ( empty( $_POST['omc_secrets'] ) ) {
			return;
		}

		check_admin_referer( self::SECRETS_ACTION );

		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change credentials.', 'oh-my-cache' ), '', [ 'response' => 403 ] );
		}

		if ( ! empty( $_POST['forget'] ) ) {
			$key = sanitize_key( wp_unslash( $_POST['forget'] ) );

			if ( in_array( $key, Options::SECRET_KEYS, true ) ) {
				Options::forget_stored_secret( $key );
				add_settings_error( 'oh_my_cache', 'omc_secret_forgotten', __( 'Stored credential deleted.', 'oh-my-cache' ), 'success' );
			}

			return;
		}

		$incoming = [];

		foreach ( Options::SECRET_KEYS as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );

			// An empty field means "leave it alone", not "delete it".
			if ( '' === $value ) {
				continue;
			}

			$incoming[ $key ] = $value;
		}

		if ( $incoming ) {
			Options::save_secrets( $incoming );
			add_settings_error( 'oh_my_cache', 'omc_secret_saved', __( 'Credentials saved.', 'oh-my-cache' ), 'success' );
		}
	}

	/**
	 * A credential row, rendered inside whichever block the credential belongs to.
	 *
	 * @param string $key         One of Options::SECRET_KEYS.
	 * @param string $label       Field label.
	 * @param string $description Extra help text.
	 */
	private function secret_row( string $key, string $label, string $description = '' ): void {
		$source   = Options::secret_source( $key );
		$external = in_array( $source, [ 'env', 'constant' ], true );

		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';

		if ( $external ) {
			/*
			 * No input at all. Offering one would invite somebody to type a value this plugin
			 * would then refuse to store, which is worse than not asking.
			 */
			printf(
				'<code>%s</code> <span class="description">%s</span>',
				esc_html( Redactor::mask( Options::secret( $key ) ) ),
				esc_html(
					'env' === $source
						? __( 'set by an environment variable, never stored in the database', 'oh-my-cache' )
						: __( 'set by a constant in wp-config.php, never stored in the database', 'oh-my-cache' )
				)
			);
		} else {
			printf(
				'<input type="password" form="omc-secrets-form" name="%s" value="" autocomplete="new-password" class="regular-text" placeholder="%s" /> ',
				esc_attr( $key ),
				esc_attr(
					'database' === $source
						? Redactor::mask( Options::secret( $key ) )
						: __( 'not set', 'oh-my-cache' )
				)
			);

			printf(
				'<button type="submit" form="omc-secrets-form" class="button">%s</button>',
				esc_html__( 'Save credential', 'oh-my-cache' )
			);

			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: environment variable name. */
						__( 'Leave blank to keep the current value. Better still, set %s in your environment: it then never touches the database, so it cannot end up in a backup or a staging copy.', 'oh-my-cache' ),
						Options::constant_name( $key )
					)
				)
			);
		}

		if ( '' !== $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}

		echo '</td></tr>';
	}

	/**
	 * Buttons for deleting credentials the environment has superseded.
	 *
	 * The credentials form itself, with its nonce, is emitted at the top of render(): the inputs
	 * scattered through the tabs reach it by `form` attribute, since HTML forms cannot nest.
	 */
	private function render_secrets_fields(): void {
		$orphans = Options::orphaned_secrets();

		if ( ! $orphans ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p>',
			esc_html__( 'These credentials are still stored in the database even though your environment now supplies them. The environment wins, so the stored copies are dead weight sitting in your backups.', 'oh-my-cache' )
		);

		foreach ( $orphans as $key ) {
			echo '<form method="post" style="display:inline-block;margin-right:8px">';
			wp_nonce_field( self::SECRETS_ACTION );
			echo '<input type="hidden" name="omc_secrets" value="1" />';
			printf( '<input type="hidden" name="forget" value="%s" />', esc_attr( $key ) );
			printf(
				'<button type="submit" class="button button-small">%s</button>',
				esc_html(
					sprintf(
						/* translators: %s: credential key. */
						__( 'Delete stored %s', 'oh-my-cache' ),
						$key
					)
				)
			);
			echo '</form>';
		}

		echo '</div>';
	}

	/* --------------------------------------------------------------------- */
	/* Field helpers                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * @param string $driver Driver id.
	 */
	private function test_button( string $driver ): void {
		printf(
			'<tr><th scope="row">%s</th><td><button type="button" class="button omc-test" data-driver="%s">%s</button> <span class="omc-test-result"></span></td></tr>',
			esc_html__( 'Connection', 'oh-my-cache' ),
			esc_attr( $driver ),
			esc_html__( 'Test connection', 'oh-my-cache' )
		);
	}

	/**
	 * @param string $name        Field name inside the option array.
	 * @param string $label       Label.
	 * @param bool   $checked     State.
	 * @param string $description Optional help text.
	 */
	private function checkbox( string $name, string $label, bool $checked, string $description = '' ): void {
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s" value="1" %s /> %s</label>%s</td></tr>',
			esc_html( $label ),
			esc_attr( $this->field_name( $name ) ),
			checked( $checked, true, false ),
			esc_html__( 'Enabled', 'oh-my-cache' ),
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>'
		);
	}

	/**
	 * Post types worth offering a switch for.
	 *
	 * Viewable ones only: something with no front end has no cached page. Attachments have their
	 * own row above, and two switches for one thing is how a settings screen starts lying.
	 *
	 * @return array<int, \WP_Post_Type>
	 */
	public static function purgeable_post_types(): array {
		$types = [];

		foreach ( get_post_types( [], 'objects' ) as $post_type ) {
			if ( 'attachment' === $post_type->name || ! is_post_type_viewable( $post_type ) ) {
				continue;
			}

			$types[] = $post_type;
		}

		return $types;
	}

	/**
	 * @param string $name        Field name.
	 * @param string $label       Label.
	 * @param string $value       Current value.
	 * @param string $placeholder Placeholder.
	 * @param string $description Help text.
	 */
	private function text( string $name, string $label, string $value, string $placeholder = '', string $description = '' ): void {
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="%s" value="%s" placeholder="%s" class="regular-text" />%s</td></tr>',
			esc_html( $label ),
			esc_attr( $this->field_name( $name ) ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>'
		);
	}

	/**
	 * @param string                $name        Field name.
	 * @param string                $label       Label.
	 * @param array<string, string> $choices     Value to label.
	 * @param string                $value       Current value.
	 * @param string                $description Help text.
	 */
	private function select( string $name, string $label, array $choices, string $value, string $description = '' ): void {
		printf(
			'<tr><th scope="row">%s</th><td><select name="%s">',
			esc_html( $label ),
			esc_attr( $this->field_name( $name ) )
		);

		foreach ( $choices as $key => $text ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $key ),
				selected( $value, (string) $key, false ),
				esc_html( $text )
			);
		}

		printf(
			'</select>%s</td></tr>',
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>'
		);
	}

	/**
	 * @param string $name  Field name.
	 * @param string $value Current value.
	 */
	private function dispatch_select( string $name, string $value ): void {
		$this->select(
			$name,
			__( 'Dispatch override', 'oh-my-cache' ),
			[
				'inherit'  => __( 'Use the global setting', 'oh-my-cache' ),
				'realtime' => __( 'Always clear immediately', 'oh-my-cache' ),
				'queue'    => __( 'Always queue', 'oh-my-cache' ),
			],
			$value
		);
	}

	/**
	 * Wrap a bare key into the option array namespace.
	 *
	 * @param string $name Key such as drivers[redis][host].
	 */
	private function field_name( string $name ): string {
		if ( ! str_contains( $name, '[' ) ) {
			return Options::OPTION_SETTINGS . '[' . $name . ']';
		}

		$first = strstr( $name, '[', true );
		$rest  = substr( $name, strlen( (string) $first ) );

		return Options::OPTION_SETTINGS . '[' . $first . ']' . $rest;
	}

	/* --------------------------------------------------------------------- */

	/**
	 * @param mixed $value Raw value.
	 */
	private static function dispatch_value( mixed $value ): string {
		$value = (string) $value;

		return in_array( $value, [ 'inherit', 'realtime', 'queue' ], true ) ? $value : 'inherit';
	}

	/**
	 * @param string $path Raw path.
	 */
	private static function path( string $path ): string {
		$path = trim( sanitize_text_field( $path ) );

		// No traversal, and no null bytes.
		$path = str_replace( [ '..', "\0" ], '', $path );

		return rtrim( $path, '/\\' );
	}

	/**
	 * @param string $levels Raw levels string.
	 */
	private static function levels( string $levels ): string {
		$parts = array_filter(
			array_map( 'intval', explode( ':', $levels ) ),
			static fn ( int $n ): bool => $n > 0 && $n <= 2
		);

		return $parts ? implode( ':', $parts ) : '1:2';
	}

	/**
	 * Turn the posted checkboxes into the list of post types to leave alone.
	 *
	 * Only the types this screen offered are decided here. A slug stored as off but not currently
	 * registered is kept, so deactivating the plugin that registers it does not switch its purging
	 * back on.
	 *
	 * @param mixed $posted Posted post_types map, slug to "1".
	 * @return array<int, string>
	 */
	private static function disabled_post_types_from( mixed $posted ): array {
		$posted = is_array( $posted ) ? $posted : [];
		$off    = Options::disabled_post_types();

		foreach ( self::purgeable_post_types() as $post_type ) {
			$off = array_diff( $off, [ $post_type->name ] );

			if ( empty( $posted[ $post_type->name ] ) ) {
				$off[] = $post_type->name;
			}
		}

		sort( $off );

		return array_values( array_unique( $off ) );
	}

	/**
	 * @param mixed $value         Raw value.
	 * @param int   $min           Minimum.
	 * @param int   $max           Maximum.
	 * @param int   $default_value Fallback.
	 */
	private static function int_in_range( mixed $value, int $min, int $max, int $default_value ): int {
		$value = (int) $value;

		return ( $value >= $min && $value <= $max ) ? $value : $default_value;
	}

	/**
	 * @param mixed $value         Raw value.
	 * @param float $min           Minimum.
	 * @param float $max           Maximum.
	 * @param float $default_value Fallback.
	 */
	private static function float_in_range( mixed $value, float $min, float $max, float $default_value ): float {
		$value = (float) $value;

		return ( $value >= $min && $value <= $max ) ? $value : $default_value;
	}
}
