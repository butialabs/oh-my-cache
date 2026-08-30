<?php
/**
 * Step 3: what the CDN should cache.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Wizard\Steps;

use OhMyCache\Cloudflare\CacheRules;
use OhMyCache\Cloudflare\Exception\ApiException;
use OhMyCache\Support\Options;
use OhMyCache\Wizard\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Three related decisions, presented as one: cache pages for visitors, cache static files, and
 * apply sensible Cloudflare settings.
 *
 * These were three separate screens once and setup felt like paperwork. They amount to one
 * question, how hard should the CDN work for you, so they are asked once.
 *
 * Everything here is optional and everything here is reversible. The zone settings are captured
 * before they are changed, so Undo puts them back exactly.
 */
final class RulesStep extends AbstractStep {

	public function id(): string {
		return 'rules';
	}

	public function title(): string {
		return __( 'Caching', 'oh-my-cache' );
	}

	public function is_complete(): bool {
		$zone = $this->zone_id();

		if ( '' === $zone ) {
			return false;
		}

		try {
			return $this->cache_rules()->exists( $zone, CacheRules::GUEST )
				|| $this->cache_rules()->exists( $zone, CacheRules::STATIC );
		} catch ( ApiException $e ) {
			return false;
		}
	}

	public function can_skip(): bool {
		return true;
	}

	public function primary_label(): string {
		return __( 'Apply and continue', 'oh-my-cache' );
	}

	public function render(): void {
		if ( '' === $this->zone_id() ) {
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html__( 'Connect Cloudflare on the previous tab to set this up, or skip it.', 'oh-my-cache' )
			);

			return;
		}

		$this->paragraph(
			__( 'Choose how much work Cloudflare does for you. All of this is optional and every part can be undone from this screen later.', 'oh-my-cache' )
		);

		$this->table_open();

		$this->option_row(
			'cache_guest',
			__( 'Serve pages from Cloudflare', 'oh-my-cache' ),
			__( 'Visitors who are not logged in get pages straight from Cloudflare instead of waiting for your server. Anyone with a login, comment or shopping cart cookie is excluded, so nobody ever sees somebody else\'s page.', 'oh-my-cache' ),
			true
		);

		$this->option_row(
			'cache_static',
			__( 'Keep images, CSS and fonts longer', 'oh-my-cache' ),
			__( 'These rarely change, so Cloudflare and your visitors\' browsers can hold on to them for a year. WordPress already puts a version number in the address, so an updated file arrives under a new one.', 'oh-my-cache' ),
			true
		);

		$this->option_row(
			'zone_settings',
			__( 'Apply recommended Cloudflare settings', 'oh-my-cache' ),
			__( 'A set of defaults that suit WordPress: newer connection protocols, sensible TLS, Rocket Loader off. Your current values are saved first, so this can be undone.', 'oh-my-cache' ),
			true
		);

		$this->table_close();

		$this->render_edge_ttl();
		$this->render_current_state();
	}

	/**
	 * @param array<string, mixed> $input Form input.
	 */
	public function apply( array $input ): StepResult {
		$zone = $this->zone_id();

		if ( '' === $zone ) {
			return StepResult::failure( __( 'Connect Cloudflare first, or skip this step.', 'oh-my-cache' ) );
		}

		$details  = [];
		$failures = [];

		if ( ! empty( $input['cache_guest'] ) ) {
			$details[] = $this->apply_rule( $zone, CacheRules::GUEST, __( 'Pages for visitors', 'oh-my-cache' ), $failures );
		} else {
			$details[] = $this->remove_rule( $zone, CacheRules::GUEST, __( 'Pages for visitors', 'oh-my-cache' ) );
		}

		if ( ! empty( $input['cache_static'] ) ) {
			$details[] = $this->apply_rule( $zone, CacheRules::STATIC, __( 'Images, CSS and fonts', 'oh-my-cache' ), $failures );
		} else {
			$details[] = $this->remove_rule( $zone, CacheRules::STATIC, __( 'Images, CSS and fonts', 'oh-my-cache' ) );
		}

		if ( ! empty( $input['zone_settings'] ) ) {
			foreach ( $this->zone_settings()->apply( $zone ) as $result ) {
				if ( 'applied' === $result['status'] ) {
					continue;
				}

				// Plan-gated settings are information, not failure.
				$details[] = [
					'label'  => $result['label'],
					'status' => 'unavailable' === $result['status']
						? __( 'not on your plan', 'oh-my-cache' )
						: __( 'failed', 'oh-my-cache' ),
					'detail' => 'unavailable' === $result['status'] ? '' : $result['detail'],
				];
			}
		}

		$ttl = isset( $input['edge_ttl'] ) ? max( 0, min( 86400, (int) $input['edge_ttl'] ) ) : 0;
		$this->save_ttl( $ttl, ! empty( $input['cache_guest'] ) );

		if ( $failures ) {
			return StepResult::failure( implode( ' ', $failures ) );
		}

		return StepResult::success( __( 'Cloudflare is set up.', 'oh-my-cache' ), array_values( array_filter( $details ) ) );
	}

	public function revert(): StepResult {
		$zone = $this->zone_id();

		if ( '' === $zone ) {
			return StepResult::failure( __( 'No Cloudflare site is connected.', 'oh-my-cache' ) );
		}

		try {
			$this->cache_rules()->remove( $zone, CacheRules::GUEST );
			$this->cache_rules()->remove( $zone, CacheRules::STATIC );
		} catch ( ApiException $e ) {
			return StepResult::failure( $e->getMessage() );
		}

		$this->zone_settings()->revert( $zone );

		$settings                        = Options::all();
		$settings['edge']['ttl_seconds'] = 0;
		Options::save( $settings );

		return StepResult::success( __( 'Rules removed and your previous Cloudflare settings restored.', 'oh-my-cache' ) );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * @param string             $zone     Zone id.
	 * @param string             $type     Rule type.
	 * @param string             $label    Human label.
	 * @param array<int, string> $failures Collected failure messages, by reference.
	 * @return array<string, string>
	 */
	private function apply_rule( string $zone, string $type, string $label, array &$failures ): array {
		try {
			$this->cache_rules()->apply( $zone, $type );
		} catch ( ApiException $e ) {
			$failures[] = $e->getMessage();

			return [
				'label'  => $label,
				'status' => __( 'failed', 'oh-my-cache' ),
				'detail' => $e->getMessage(),
			];
		}

		return [
			'label'  => $label,
			'status' => __( 'on', 'oh-my-cache' ),
			'detail' => '',
		];
	}

	/**
	 * @param string $zone  Zone id.
	 * @param string $type  Rule type.
	 * @param string $label Human label.
	 * @return array<string, string>|null
	 */
	private function remove_rule( string $zone, string $type, string $label ): ?array {
		try {
			$removed = $this->cache_rules()->remove( $zone, $type );
		} catch ( ApiException $e ) {
			return null;
		}

		return $removed
			? [
				'label'  => $label,
				'status' => __( 'turned off', 'oh-my-cache' ),
				'detail' => '',
			]
			: null;
	}

	/**
	 * Store the edge TTL, refusing a non-zero value until a purge has been proved to work.
	 *
	 * @param int  $ttl     Requested TTL.
	 * @param bool $enabled Whether guest caching was requested at all.
	 */
	private function save_ttl( int $ttl, bool $enabled ): void {
		$settings = Options::all();

		if ( ! $enabled ) {
			$settings['edge']['ttl_seconds'] = 0;
			Options::save( $settings );

			return;
		}

		/*
		 * The interlock. A non-zero TTL is only accepted once a purge has actually been proved to
		 * work on the last step. Otherwise the worst failure this plugin can produce becomes
		 * reachable in one click: stale pages pinned at the edge for hours, with no way for a
		 * visitor to get past them.
		 */
		$settings['edge']['ttl_seconds'] = Options::cf_state( 'test_purge_ok', false ) ? $ttl : 0;

		Options::save( $settings );
	}

	private function render_edge_ttl(): void {
		$proved = (bool) Options::cf_state( 'test_purge_ok', false );

		printf( '<h3>%s</h3>', esc_html__( 'How long Cloudflare holds a page', 'oh-my-cache' ) );

		$this->table_open();

		printf(
			'<tr><th scope="row">%s</th><td><input type="number" name="edge_ttl" value="%d" min="0" max="86400" class="small-text" /> %s<p class="description">%s</p></td></tr>',
			esc_html__( 'Seconds', 'oh-my-cache' ),
			(int) Options::get( 'edge.ttl_seconds', 0 ),
			esc_html__( 'seconds', 'oh-my-cache' ),
			esc_html(
				$proved
					? __( 'A test purge has already succeeded, so this can safely be raised.', 'oh-my-cache' )
					: __( 'Leave this at zero for now. The last step runs a real purge, and you can raise it once that succeeds. A page held at the edge while purging is broken stays stale for the whole time, and visitors cannot get past it.', 'oh-my-cache' )
			)
		);

		$this->table_close();
	}

	/**
	 * Show what is currently installed, so re-running the wizard is not a guessing game.
	 */
	private function render_current_state(): void {
		$zone = $this->zone_id();

		try {
			$guest  = $this->cache_rules()->exists( $zone, CacheRules::GUEST );
			$static = $this->cache_rules()->exists( $zone, CacheRules::STATIC );
			$count  = $this->cache_rules()->count( $zone );
		} catch ( ApiException $e ) {
			return;
		}

		if ( ! $guest && ! $static ) {
			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of cache rules already on the zone. */
					__( 'This site already has %d cache rule(s) in Cloudflare. Applying again updates ours in place rather than adding duplicates.', 'oh-my-cache' ),
					$count
				)
			)
		);
	}

	/**
	 * @param string $name    Field name.
	 * @param string $label   Label.
	 * @param string $help    Explanation.
	 * @param bool   $default_on Whether it starts checked.
	 */
	private function option_row( string $name, string $label, string $help, bool $default_on ): void {
		$zone    = $this->zone_id();
		$checked = $default_on;

		if ( 'cache_guest' === $name || 'cache_static' === $name ) {
			try {
				$type    = 'cache_guest' === $name ? CacheRules::GUEST : CacheRules::STATIC;
				$checked = $this->cache_rules()->exists( $zone, $type ) ?: $default_on;
			} catch ( ApiException $e ) {
				$checked = $default_on;
			}
		}

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s" value="1" %s /> %s</label><p class="description">%s</p></td></tr>',
			esc_html( $label ),
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html__( 'Enabled', 'oh-my-cache' ),
			esc_html( $help )
		);
	}
}
