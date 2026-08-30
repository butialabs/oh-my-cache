<?php
/**
 * ClientException.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cloudflare\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * A 4xx that is our fault: a malformed request, a URL outside the zone, a plan that does
 * not include the feature.
 *
 * Not retryable, for the same reason: the request will be rejected identically next time.
 */
final class ClientException extends ApiException {

	public function is_retryable(): bool {
		return false;
	}
}
