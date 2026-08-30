<?php
/**
 * Base Cloudflare API exception.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cloudflare\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the client throws.
 *
 * Carrying retryability on the exception is what lets the queue tell "try again in five
 * minutes" apart from "this will never work", instead of burning six attempts on a malformed
 * request or an invalid token.
 */
class ApiException extends \RuntimeException {

	/** @var array<int, array<string, mixed>> */
	protected array $api_errors = [];

	protected ?int $retry_after = null;

	/**
	 * @param string                           $message    Message.
	 * @param int                              $code       HTTP status.
	 * @param array<int, array<string, mixed>> $api_errors Cloudflare errors array.
	 */
	public function __construct( string $message, int $code = 0, array $api_errors = [] ) {
		parent::__construct( $message, $code );

		$this->api_errors = $api_errors;
	}

	/**
	 * Whether retrying could plausibly succeed.
	 */
	public function is_retryable(): bool {
		return true;
	}

	/**
	 * Server-requested delay, when there is one.
	 */
	public function retry_after(): ?int {
		return $this->retry_after;
	}

	/**
	 * Cloudflare's structured errors.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function api_errors(): array {
		return $this->api_errors;
	}

	/**
	 * The numeric Cloudflare error codes.
	 *
	 * @return array<int, int>
	 */
	public function error_codes(): array {
		$codes = [];

		foreach ( $this->api_errors as $error ) {
			if ( isset( $error['code'] ) ) {
				$codes[] = (int) $error['code'];
			}
		}

		return $codes;
	}
}
