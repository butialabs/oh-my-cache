<?php
/**
 * ServerException.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cloudflare\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * A 5xx or a timeout on Cloudflare's side.
 *
 * Retryable: this is exactly what the backoff schedule exists for.
 */
final class ServerException extends ApiException {

	public function is_retryable(): bool {
		return true;
	}
}
