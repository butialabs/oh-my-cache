<?php
/**
 * AuthException.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cloudflare\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Invalid or insufficient credentials: HTTP 401 or 403.
 *
 * Never retryable. A token that is wrong now will be wrong in six hours, and quietly retrying
 * hides the one thing the operator needs to be told.
 */
final class AuthException extends ApiException {

	public function is_retryable(): bool {
		return false;
	}
}
