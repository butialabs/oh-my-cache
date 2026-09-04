<?php
/**
 * Restore the visitor IP behind Cloudflare.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Http;

use OhMyCache\Queue\Scheduler;
use OhMyCache\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the real visitor IP back into REMOTE_ADDR when a site sits behind Cloudflare without
 * nginx set_real_ip_from configured.
 *
 * Without it every anti-spam plugin, comment filter and login limiter sees a Cloudflare IP and
 * either blocks everyone or nobody.
 *
 * Timing is the hard part. The right place for this is before any plugin loads, so the
 * recommended path is a constant in wp-config.php or a one-line mu-plugin; the settings screen
 * offers the snippet. The option-driven fallback can only act on plugins_loaded priority 1, by
 * which point a plugin that read REMOTE_ADDR while its own file loaded has already seen the
 * wrong value. App for Cloudflare has the same limitation, and its version runs unconditionally
 * with no way to turn it off.
 *
 * This is a decision to trust a header, so it is off by default, it only acts when the peer is
 * genuinely inside a Cloudflare range, and the range list is refreshed weekly by cron rather
 * than frozen in the source. It never makes a remote call during a page request.
 */
final class TrueClientIp {

	/**
	 * Register, honouring the constant first.
	 */
	public static function maybe_register(): void {
		if ( self::forced_by_constant() ) {
			self::apply();
			return;
		}

		/*
		 * The option path needs the database, so it cannot run this early without forcing an
		 * options query before the rest of WordPress wants one. plugins_loaded priority 1 is
		 * the earliest honest place.
		 */
		add_action( 'plugins_loaded', [ self::class, 'apply_from_settings' ], 1 );
	}

	/**
	 * Whether the operator asked for either header rewrite, by constant or by setting.
	 */
	public static function is_enabled(): bool {
		if ( self::forced_by_constant() ) {
			return true;
		}

		return Options::flag( 'edge.true_client_ip', false ) || Options::flag( 'edge.force_https_from_proto', false );
	}

	/**
	 * Whether wp-config.php or the environment turned this on.
	 */
	private static function forced_by_constant(): bool {
		$name = 'OH_MY_CACHE_TRUE_CLIENT_IP';

		if ( defined( $name ) ) {
			return (bool) constant( $name );
		}

		return 'true' === strtolower( (string) getenv( $name ) );
	}

	/**
	 * Settings-driven entry point.
	 */
	public static function apply_from_settings(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		self::apply();
	}

	/**
	 * Do the rewriting.
	 */
	public static function apply(): void {
		$forwarded = self::header( 'HTTP_CF_CONNECTING_IP' );
		$remote    = self::header( 'REMOTE_ADDR' );

		if ( '' === $remote ) {
			return;
		}

		// Only trust the headers when the peer really is Cloudflare.
		if ( ! self::ip_in_ranges( $remote, self::ranges() ) ) {
			return;
		}

		/*
		 * A separate switch from the IP rewrite, and deliberately so. Trusting
		 * X-Forwarded-Proto is a foot-gun when another proxy in front also sets it; it exists
		 * only to break the redirect loop Cloudflare Flexible SSL causes.
		 */
		if ( self::forced_by_constant() || Options::flag( 'edge.force_https_from_proto', false ) ) {
			if ( 'https' === strtolower( self::header( 'HTTP_X_FORWARDED_PROTO' ) ) ) {
				$_SERVER['HTTPS'] = 'on';
			}
		}

		if ( '' === $forwarded || ! filter_var( $forwarded, FILTER_VALIDATE_IP ) ) {
			return;
		}

		if ( self::forced_by_constant() || Options::flag( 'edge.true_client_ip', false ) ) {
			$_SERVER['REMOTE_ADDR'] = $forwarded;
		}
	}

	/**
	 * Cloudflare IP ranges: the cron-refreshed list, falling back to the bundled one.
	 *
	 * Never fetches during a page request. A stale list fails closed, leaving REMOTE_ADDR alone.
	 *
	 * @return array<int, string>
	 */
	public static function ranges(): array {
		$stored = get_option( Options::OPTION_CF_IPS, [] );

		if ( is_array( $stored ) && ! empty( $stored['ranges'] ) && is_array( $stored['ranges'] ) ) {
			return array_map( 'strval', $stored['ranges'] );
		}

		return self::bundled_ranges();
	}

	/**
	 * Fallback list, used until the weekly cron replaces it.
	 *
	 * Published ranges change rarely but they do change, which is why this is a fallback and
	 * not the source of truth.
	 *
	 * @return array<int, string>
	 */
	public static function bundled_ranges(): array {
		return [
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		];
	}

	/**
	 * Whether an IP falls inside any of the given CIDR ranges.
	 *
	 * @param string             $ip     IP address.
	 * @param array<int, string> $ranges CIDR ranges.
	 */
	public static function ip_in_ranges( string $ip, array $ranges ): bool {
		$binary = self::to_binary( $ip );

		if ( '' === $binary ) {
			return false;
		}

		foreach ( $ranges as $range ) {
			if ( self::ip_in_range( $binary, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compare a binary IP against one CIDR range.
	 *
	 * @param string $binary Binary IP from to_binary().
	 * @param string $range  CIDR range.
	 */
	private static function ip_in_range( string $binary, string $range ): bool {
		if ( ! str_contains( $range, '/' ) ) {
			return $binary === self::to_binary( $range );
		}

		[ $subnet, $bits ] = explode( '/', $range, 2 );

		$subnet_binary = self::to_binary( $subnet );
		$bits          = (int) $bits;

		if ( '' === $subnet_binary || strlen( $subnet_binary ) !== strlen( $binary ) ) {
			// Different address families never match.
			return false;
		}

		return substr( $binary, 0, $bits ) === substr( $subnet_binary, 0, $bits );
	}

	/**
	 * IP address as a string of bits, or empty string when unparseable.
	 *
	 * @param string $ip IP address.
	 */
	private static function to_binary( string $ip ): string {
		$packed = @inet_pton( $ip );

		if ( false === $packed ) {
			return '';
		}

		$bits = '';

		foreach ( str_split( $packed ) as $char ) {
			$bits .= str_pad( decbin( ord( $char ) ), 8, '0', STR_PAD_LEFT );
		}

		return $bits;
	}

	/**
	 * Read a server header safely.
	 *
	 * @param string $key $_SERVER key.
	 */
	private static function header( string $key ): string {
		if ( empty( $_SERVER[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$value = wp_unslash( $_SERVER[ $key ] );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Cron callback: refresh the range list from Cloudflare.
	 */
	public static function refresh_ranges(): void {
		if ( ! self::is_enabled() ) {
			wp_clear_scheduled_hook( Scheduler::HOOK_CF_IPS );

			return;
		}

		$response = wp_remote_get(
			'https://api.cloudflare.com/client/v4/ips',
			[ 'timeout' => 10 ]
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['success'] ) || empty( $body['result'] ) ) {
			return;
		}

		$ranges = array_merge(
			(array) ( $body['result']['ipv4_cidrs'] ?? [] ),
			(array) ( $body['result']['ipv6_cidrs'] ?? [] )
		);

		$ranges = array_values( array_filter( array_map( 'strval', $ranges ) ) );

		if ( ! $ranges ) {
			return;
		}

		update_option(
			Options::OPTION_CF_IPS,
			[
				'ranges'     => $ranges,
				'fetched_at' => time(),
			],
			false
		);
	}
}
