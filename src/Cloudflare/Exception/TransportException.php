<?php
/**
 * TransportException.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cloudflare\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The request never reached Cloudflare: DNS failure, refused connection, WP_Error.
 *
 * Retryable. Often it is the local network, not the API.
 */
final class TransportException extends ApiException {

	public function is_retryable(): bool {
		return true;
	}
}
