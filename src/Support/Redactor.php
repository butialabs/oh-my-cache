<?php
/**
 * Secret redaction for anything that can reach storage or the screen.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Strips credentials out of strings before they land in the jobs table.
 *
 * `last_error` is rendered in wp-admin, so an API error echoing back an Authorization header
 * would otherwise put a live token on screen and in the database. This solves that problem
 * and only that one: it is not a substitute for esc_html() on output. The two defend against
 * different things and both are required.
 */
final class Redactor {

	private const MASK = '[redacted]';

	/**
	 * Redact every known secret plus anything that looks like a bearer token.
	 *
	 * @param string $text Arbitrary text, typically an exception message or API body.
	 */
	public static function scrub( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}

		$needles = [];
		foreach ( Options::SECRET_KEYS as $key ) {
			$value = Options::secret( $key );
			// Skip trivially short values; replacing "0" everywhere would mangle the message.
			if ( strlen( $value ) >= 8 ) {
				$needles[] = $value;
			}
		}

		if ( $needles ) {
			$text = str_replace( $needles, self::MASK, $text );
		}

		$patterns = [
			// Authorization: Bearer <token>
			'/(Bearer\s+)[A-Za-z0-9_\-\.]{16,}/i',
			// X-Auth-Key: <key> and friends, header or JSON shaped.
			'/(X-Auth-Key["\':\s]+)[A-Za-z0-9_\-]{16,}/i',
			'/(X-Auth-Email["\':\s]+)[^\s"\',]+/i',
			// Anything self-describing as a token or secret in a JSON body.
			'/(["\'](?:token|api_token|api_key|secret|password)["\']\s*:\s*["\'])[^"\']{8,}/i',
		];

		foreach ( $patterns as $pattern ) {
			$text = (string) preg_replace( $pattern, '$1' . self::MASK, $text );
		}

		return $text;
	}

	/**
	 * Mask a secret for display: keep the last four characters so an operator can tell which
	 * credential is in play without exposing it.
	 *
	 * @param string $secret Raw secret.
	 */
	public static function mask( string $secret ): string {
		$length = strlen( $secret );

		if ( 0 === $length ) {
			return '';
		}

		if ( $length <= 4 ) {
			return str_repeat( "\u{2022}", $length );
		}

		return str_repeat( "\u{2022}", 4 ) . substr( $secret, -4 );
	}
}
