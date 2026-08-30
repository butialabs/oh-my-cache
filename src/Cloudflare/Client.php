<?php
/**
 * Cloudflare API client.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Cloudflare;

use OhMyCache\Cloudflare\Exception\ApiException;
use OhMyCache\Cloudflare\Exception\AuthException;
use OhMyCache\Cloudflare\Exception\ClientException;
use OhMyCache\Cloudflare\Exception\RateLimitException;
use OhMyCache\Cloudflare\Exception\ServerException;
use OhMyCache\Cloudflare\Exception\TransportException;
use OhMyCache\Support\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * A deliberately small surface: eleven endpoints, covering purging and the four onboarding
 * steps, and nothing else.
 *
 * There is no blind inline retry loop here. App for Cloudflare reissues any 5xx once,
 * immediately, with no backoff, which helps with a single dropped packet and does nothing for a
 * rate limit or an outage. Retrying is the queue's job now, with a schedule and a ceiling, so
 * this layer's only responsibility is to classify the failure accurately.
 */
final class Client {

	private const BASE = 'https://api.cloudflare.com/client/v4/';

	/**
	 * @param float|null $timeout Override the request timeout.
	 */
	public function __construct( private readonly ?float $timeout = null ) {}

	/* --------------------------------------------------------------------- */
	/* Tokens                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Verify the configured token.
	 *
	 * @return array<string, mixed>
	 */
	public function verify_token(): array {
		return (array) $this->request( 'GET', 'user/tokens/verify' )->result;
	}

	/**
	 * Read a token's details, including its permission groups and expiry.
	 *
	 * @param string $token_id Token id from verify_token().
	 * @return array<string, mixed>
	 */
	public function token_details( string $token_id ): array {
		return (array) $this->request( 'GET', 'user/tokens/' . rawurlencode( $token_id ) )->result;
	}

	/* --------------------------------------------------------------------- */
	/* Zones                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * List zones, optionally filtered by exact name.
	 *
	 * @param string $name Zone name.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_zones( string $name = '' ): array {
		$query = [ 'per_page' => 50 ];

		if ( '' !== $name ) {
			$query['name'] = $name;
		}

		return (array) $this->request( 'GET', 'zones', [ 'query' => $query ] )->result;
	}

	/**
	 * Read one zone.
	 *
	 * @param string $zone_id Zone id.
	 * @return array<string, mixed>
	 */
	public function get_zone( string $zone_id ): array {
		return (array) $this->request( 'GET', 'zones/' . rawurlencode( $zone_id ) )->result;
	}

	/**
	 * Read every zone setting.
	 *
	 * @param string $zone_id Zone id.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_zone_settings( string $zone_id ): array {
		return (array) $this->request( 'GET', 'zones/' . rawurlencode( $zone_id ) . '/settings' )->result;
	}

	/**
	 * Patch a batch of zone settings.
	 *
	 * @param string                           $zone_id Zone id.
	 * @param array<int, array<string, mixed>> $items   [{ id, value }, ...].
	 * @return array<int, array<string, mixed>>
	 */
	public function patch_zone_settings( string $zone_id, array $items ): array {
		return (array) $this->request(
			'PATCH',
			'zones/' . rawurlencode( $zone_id ) . '/settings',
			[ 'json' => [ 'items' => array_values( $items ) ] ]
		)->result;
	}

	/**
	 * Patch one setting that lives on its own endpoint.
	 *
	 * Several of these are plan-gated and answer 4xx on Free, so callers apply them
	 * individually and treat a failure as informational rather than fatal.
	 *
	 * @param string               $zone_id  Zone id.
	 * @param string               $endpoint Path under the zone, e.g. settings/speed_brain.
	 * @param array<string, mixed> $body     Request body.
	 * @param string               $method   HTTP method.
	 * @return mixed
	 */
	public function patch_zone_setting( string $zone_id, string $endpoint, array $body, string $method = 'PATCH' ): mixed {
		return $this->request(
			$method,
			'zones/' . rawurlencode( $zone_id ) . '/' . ltrim( $endpoint, '/' ),
			[ 'json' => $body ]
		)->result;
	}

	/* --------------------------------------------------------------------- */
	/* Cache rules                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * Read the cache-settings phase entrypoint ruleset.
	 *
	 * Returns an empty array when the phase does not exist yet, which is the normal state for a
	 * zone nobody has added a cache rule to.
	 *
	 * @param string $zone_id Zone id.
	 * @return array<string, mixed>
	 */
	public function get_cache_ruleset( string $zone_id ): array {
		try {
			$result = $this->request(
				'GET',
				'zones/' . rawurlencode( $zone_id ) . '/rulesets/phases/http_request_cache_settings/entrypoint'
			)->result;
		} catch ( ClientException $e ) {
			// 404: the phase has no ruleset yet.
			if ( 404 === $e->getCode() ) {
				return [];
			}

			throw $e;
		}

		return (array) $result;
	}

	/**
	 * Create the phase entrypoint with an initial rule set.
	 *
	 * @param string                           $zone_id Zone id.
	 * @param array<int, array<string, mixed>> $rules   Rules.
	 * @return array<string, mixed>
	 */
	public function create_cache_ruleset( string $zone_id, array $rules ): array {
		return (array) $this->request(
			'PUT',
			'zones/' . rawurlencode( $zone_id ) . '/rulesets/phases/http_request_cache_settings/entrypoint',
			[
				'json' => [
					'rules' => array_values( $rules ),
				],
			]
		)->result;
	}

	/**
	 * Append a rule to an existing ruleset.
	 *
	 * @param string               $zone_id    Zone id.
	 * @param string               $ruleset_id Ruleset id.
	 * @param array<string, mixed> $rule       Rule.
	 * @return array<string, mixed>
	 */
	public function create_cache_rule( string $zone_id, string $ruleset_id, array $rule ): array {
		return (array) $this->request(
			'POST',
			'zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules',
			[ 'json' => $rule ]
		)->result;
	}

	/**
	 * Update an existing rule in place.
	 *
	 * This is the one addition over the donor's surface, and it is what makes the wizard
	 * re-runnable: without it, running onboarding twice appends a duplicate rule.
	 *
	 * @param string               $zone_id    Zone id.
	 * @param string               $ruleset_id Ruleset id.
	 * @param string               $rule_id    Rule id.
	 * @param array<string, mixed> $rule       Rule.
	 * @return array<string, mixed>
	 */
	public function update_cache_rule( string $zone_id, string $ruleset_id, string $rule_id, array $rule ): array {
		return (array) $this->request(
			'PATCH',
			'zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules/' . rawurlencode( $rule_id ),
			[ 'json' => $rule ]
		)->result;
	}

	/**
	 * Delete a rule.
	 *
	 * @param string $zone_id    Zone id.
	 * @param string $ruleset_id Ruleset id.
	 * @param string $rule_id    Rule id.
	 */
	public function delete_cache_rule( string $zone_id, string $ruleset_id, string $rule_id ): bool {
		$this->request(
			'DELETE',
			'zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules/' . rawurlencode( $rule_id )
		);

		return true;
	}

	/* --------------------------------------------------------------------- */
	/* Purging                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Purge cache.
	 *
	 * @param string               $zone_id Zone id.
	 * @param array<string, mixed> $body    { files: [...] } or { purge_everything: true }.
	 */
	public function purge_cache( string $zone_id, array $body ): Response {
		return $this->request(
			'POST',
			'zones/' . rawurlencode( $zone_id ) . '/purge_cache',
			[ 'json' => $body ]
		);
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Issue a request and classify the outcome.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path under the API base.
	 * @param array<string, mixed> $options query and json.
	 * @throws ApiException When the call fails.
	 */
	public function request( string $method, string $path, array $options = [] ): Response {
		$headers = Credentials::headers();

		if ( ! $headers ) {
			throw new AuthException( esc_html__( 'No Cloudflare credentials are configured.', 'oh-my-cache' ), 401 );
		}

		$url = self::BASE . ltrim( $path, '/' );

		if ( ! empty( $options['query'] ) && is_array( $options['query'] ) ) {
			$url = add_query_arg( $options['query'], $url );
		}

		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => $this->timeout(),
			'headers' => $headers + [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
		];

		if ( isset( $options['json'] ) ) {
			$args['body'] = (string) wp_json_encode( $options['json'] );
		}

		$raw = wp_remote_request( $url, $args );

		if ( is_wp_error( $raw ) ) {
			/*
			 * Not escaped here on purpose. This message is stored in the jobs table and escaped
			 * where it is rendered, in QueueListTable::column_last_error(). Escaping at the throw
			 * as well would double-encode it, so an error mentioning "A & B" would be filed as
			 * "A &amp;amp; B" and read as gibberish in the one place an operator looks when
			 * something is wrong. Redactor::scrub() has already removed any credential.
			 */
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new TransportException( Redactor::scrub( $raw->get_error_message() ), 0 );
		}

		$response = Response::from_http( $raw );

		if ( $response->ok() ) {
			return $response;
		}

		// See the note above: classify() builds its message from the API body, escaped on output.
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw $this->classify( $response );
	}

	/**
	 * Turn a failed response into the right exception type.
	 *
	 * The distinction the queue cares about is retryable versus not, so that a bad token
	 * dead-letters immediately instead of consuming six attempts over seven hours.
	 *
	 * @param Response $response The failed response.
	 */
	private function classify( Response $response ): ApiException {
		$message = Redactor::scrub( $response->error_message() );
		$status  = $response->status;
		$errors  = $response->errors;

		if ( 429 === $status ) {
			return new RateLimitException( $message, $status, $errors, $response->retry_after() );
		}

		if ( 401 === $status || 403 === $status ) {
			return new AuthException( $message, $status, $errors );
		}

		if ( 408 === $status || $status >= 500 ) {
			return new ServerException( $message, $status, $errors );
		}

		if ( $status >= 400 ) {
			return new ClientException( $message, $status, $errors );
		}

		/*
		 * HTTP 200 with success:false. Cloudflare does this, and treating it as success is how
		 * you end up reporting a purge that never happened.
		 */
		return new ClientException( $message, $status ?: 400, $errors );
	}

	/**
	 * Request timeout: short when we are holding up a page render, generous in the queue.
	 */
	private function timeout(): float {
		if ( null !== $this->timeout ) {
			return $this->timeout;
		}

		return ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ? 15.0 : 5.0;
	}
}
