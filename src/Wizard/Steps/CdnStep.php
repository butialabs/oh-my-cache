<?php
/**
 * Step 2: connect the CDN.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Wizard\Steps;

use OhMyCache\Cdn\Providers;
use OhMyCache\Cloudflare\Credentials;
use OhMyCache\Cloudflare\Exception\ApiException;
use OhMyCache\Support\Options;
use OhMyCache\Support\Redactor;
use OhMyCache\Wizard\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Connect the CDN in front of the site, and work out which zone this site belongs to.
 *
 * Cloudflare is the only provider today, but the step is shaped around a provider choice rather
 * than around Cloudflare, so adding Fastly or Bunny later is a new branch here and a new driver,
 * not a rewrite of onboarding. That is why the question is "which CDN" even when there is
 * currently one answer.
 */
final class CdnStep extends AbstractStep {

	/**
	 * Permission groups the token needs. Names are matched loosely because Cloudflare words them
	 * slightly differently across accounts.
	 */
	private const REQUIRED_PERMISSIONS = [
		'Zone Read',
		'Cache Purge',
		'Zone Settings Write',
	];

	public function id(): string {
		return 'cdn';
	}

	public function title(): string {
		return __( 'CDN', 'oh-my-cache' );
	}

	public function is_complete(): bool {
		if ( ! Providers::is_managed() ) {
			return true;
		}

		return Credentials::configured() && '' !== $this->zone_id();
	}

	public function can_skip(): bool {
		return true;
	}

	public function render(): void {
		$provider = Providers::current();

		$this->paragraph(
			__( 'A CDN keeps another copy of your pages closer to your visitors. When you publish something, that copy has to be cleared too, or people keep seeing the old version.', 'oh-my-cache' )
		);

		$this->table_open();

		echo '<tr><th scope="row">' . esc_html__( 'Your CDN', 'oh-my-cache' ) . '</th><td>';
		printf( '<fieldset><legend class="screen-reader-text">%s</legend>', esc_html__( 'Your CDN', 'oh-my-cache' ) );

		foreach ( Providers::all() as $value => $label ) {
			printf(
				'<label><input type="radio" name="cdn_provider" value="%s" %s class="oh-my-cache-cdn-choice" /> <strong>%s</strong></label><br />',
				esc_attr( (string) $value ),
				checked( $provider, (string) $value, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset></td></tr>';
		$this->table_close();

		echo '<div class="oh-my-cache-cdn-block" data-oh-my-cache-cdn-block="cloudflare">';
		$this->render_cloudflare();
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $input Form input.
	 */
	public function apply( array $input ): StepResult {
		$provider = Providers::sanitize( $input['cdn_provider'] ?? '' );

		$settings                    = Options::all();
		$settings['cdn']['provider'] = $provider;

		if ( Providers::NONE !== $provider ) {
			Options::save( $settings );

			return $this->apply_cloudflare( $input );
		}

		$settings['drivers']['cloudflare']['enabled'] = false;
		Options::save( $settings );

		return StepResult::success( __( 'No CDN to clear. Only your server cache will be looked after.', 'oh-my-cache' ) );
	}

	public function revert(): StepResult {
		Options::forget_stored_secret( 'cf_api_token' );

		Options::set_cf_state(
			[
				'token_id'   => '',
				'zone_id'    => '',
				'zone_name'  => '',
				'plan'       => '',
				'account_id' => '',
			]
		);

		$settings                                     = Options::all();
		$settings['drivers']['cloudflare']['enabled'] = false;
		Options::save( $settings );

		return StepResult::success( __( 'Cloudflare disconnected.', 'oh-my-cache' ) );
	}

	/* --------------------------------------------------------------------- */

	private function render_cloudflare(): void {
		$source = Credentials::token_source();

		$this->table_open();

		if ( in_array( $source, [ 'env', 'constant' ], true ) ) {
			/*
			 * No input at all when the environment supplies the token. Offering one would invite
			 * somebody to type a value this plugin would then refuse to store.
			 */
			printf(
				'<tr><th scope="row">%s</th><td><code>%s</code> <span class="description">%s</span></td></tr>',
				esc_html__( 'API token', 'oh-my-cache' ),
				esc_html( Redactor::mask( Credentials::token() ) ),
				esc_html(
					'env' === $source
						? __( 'already set by an environment variable, and never stored in the database', 'oh-my-cache' )
						: __( 'already set in wp-config.php, and never stored in the database', 'oh-my-cache' )
				)
			);
		} else {
			printf(
				'<tr><th scope="row">%s</th><td><input type="password" name="cf_api_token" class="regular-text" autocomplete="new-password" placeholder="%s" /><p class="description">%s</p><p class="description">%s</p></td></tr>',
				esc_html__( 'API token', 'oh-my-cache' ),
				esc_attr(
					'database' === $source
						? Redactor::mask( Credentials::token() )
						: __( 'paste your token here', 'oh-my-cache' )
				),
				esc_html__( 'In Cloudflare, go to My Profile, API Tokens, Create Token. Give it permission to purge cache and edit cache rules and zone settings for this site only.', 'oh-my-cache' ),
				esc_html__( 'We never ask for a global API key. That one grants access to your whole account and cannot be limited to a single site.', 'oh-my-cache' )
			);
		}

		$zone = (string) Options::cf_state( 'zone_name', '' );

		if ( '' !== $zone ) {
			printf(
				'<tr><th scope="row">%s</th><td><code>%s</code> <span class="description">%s</span></td></tr>',
				esc_html__( 'Site found', 'oh-my-cache' ),
				esc_html( $zone ),
				esc_html(
					sprintf(
						/* translators: %s: Cloudflare plan name. */
						__( '%s plan', 'oh-my-cache' ),
						(string) Options::cf_state( 'plan', 'unknown' )
					)
				)
			);
		}

		$this->table_close();

		$this->test_summary(
			[
				__( 'The token is valid and has not expired', 'oh-my-cache' ),
				__( 'It can purge cache and edit rules for this site', 'oh-my-cache' ),
				sprintf(
					/* translators: %s: site hostname. */
					__( 'Cloudflare recognises %s as one of your sites', 'oh-my-cache' ),
					(string) wp_parse_url( home_url(), PHP_URL_HOST )
				),
			]
		);
	}

	/**
	 * Save the token, resolve the zone, and verify permissions.
	 *
	 * @param array<string, mixed> $input Form input.
	 */
	private function apply_cloudflare( array $input ): StepResult {
		$token = isset( $input['cf_api_token'] ) ? trim( (string) $input['cf_api_token'] ) : '';

		if ( '' !== $token ) {
			// Dropped automatically when the environment already supplies one.
			Options::save_secrets( [ 'cf_api_token' => $token ] );
		}

		if ( ! Credentials::configured() ) {
			return StepResult::failure( __( 'Paste your Cloudflare API token to continue, or choose "None" above.', 'oh-my-cache' ) );
		}

		try {
			$verified = $this->client()->verify_token();
		} catch ( ApiException $e ) {
			return StepResult::failure(
				sprintf(
					/* translators: %s: error message from Cloudflare. */
					__( 'Cloudflare rejected that token: %s', 'oh-my-cache' ),
					$e->getMessage()
				)
			);
		}

		$token_id = (string) ( $verified['id'] ?? '' );

		Options::set_cf_state(
			[
				'token_id'     => $token_id,
				'token_status' => (string) ( $verified['status'] ?? '' ),
				'verified_at'  => time(),
			]
		);

		$missing = $this->missing_permissions( $token_id );

		if ( $missing ) {
			return StepResult::failure(
				sprintf(
					/* translators: %s: comma separated permission names. */
					__( 'That token is valid but cannot do everything we need. Missing: %s. Edit the token in Cloudflare and try again.', 'oh-my-cache' ),
					implode( ', ', $missing )
				)
			);
		}

		try {
			$resolved = $this->zone_resolver()->resolve( true );
		} catch ( ApiException $e ) {
			return StepResult::failure( $e->getMessage() );
		}

		if ( null === $resolved ) {
			return StepResult::failure(
				sprintf(
					/* translators: %s: site hostname. */
					__( 'The token works, but Cloudflare does not list %s among the sites it can see. Check the token covers this site.', 'oh-my-cache' ),
					(string) wp_parse_url( home_url(), PHP_URL_HOST )
				)
			);
		}

		$settings                                     = Options::all();
		$settings['drivers']['cloudflare']['enabled'] = true;
		Options::save( $settings );

		$details = [];

		if ( 'free' === strtolower( $resolved['plan'] ) ) {
			$details[] = [
				'label'  => __( 'Free plan', 'oh-my-cache' ),
				'status' => '',
				'detail' => __( 'Cloudflare allows ten cache rules on this plan. The next step adds two.', 'oh-my-cache' ),
			];
		}

		return StepResult::success(
			sprintf(
				/* translators: %s: zone name. */
				__( 'Connected to %s.', 'oh-my-cache' ),
				$resolved['zone_name']
			),
			$details
		);
	}

	/**
	 * Which required permissions the token is missing.
	 *
	 * @param string $token_id Token id.
	 * @return array<int, string>
	 */
	private function missing_permissions( string $token_id ): array {
		if ( '' === $token_id ) {
			return [];
		}

		try {
			$details = $this->client()->token_details( $token_id );
		} catch ( ApiException $e ) {
			// Some tokens cannot read their own details. Not being able to check is not a failure.
			return [];
		}

		$granted = [];

		foreach ( (array) ( $details['policies'] ?? [] ) as $policy ) {
			foreach ( (array) ( $policy['permission_groups'] ?? [] ) as $group ) {
				$granted[] = (string) ( $group['name'] ?? '' );
			}
		}

		if ( ! $granted ) {
			return [];
		}

		$missing = [];

		foreach ( self::REQUIRED_PERMISSIONS as $needed ) {
			$found = false;

			foreach ( $granted as $name ) {
				if ( false !== stripos( $name, $needed ) ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$missing[] = $needed;
			}
		}

		return $missing;
	}
}
