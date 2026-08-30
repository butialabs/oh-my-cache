<?php
/**
 * HTTP 429 from the Cloudflare API.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cloudflare\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Rate limited.
 *
 * Retryable, and it carries Retry-After so the queue can wait exactly as long as the server
 * asked rather than guessing.
 *
 * App for Cloudflare classifies 429 as a plain 4xx client error and never retries it, which
 * means a burst of purges past the rate limit silently loses everything after the limit is hit.
 * Splitting it out is the difference between a delayed purge and a lost one.
 */
final class RateLimitException extends ApiException {

	/**
	 * @param string                           $message     Message.
	 * @param int                              $code        HTTP status.
	 * @param array<int, array<string, mixed>> $api_errors  Cloudflare errors.
	 * @param int|null                         $retry_after Seconds from the Retry-After header.
	 */
	public function __construct( string $message, int $code = 429, array $api_errors = [], ?int $retry_after = null ) {
		parent::__construct( $message, $code, $api_errors );

		$this->retry_after = $retry_after;
	}

	public function is_retryable(): bool {
		return true;
	}
}
