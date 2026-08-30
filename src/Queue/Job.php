<?php
/**
 * A single queued unit of work.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable view of one row of the jobs table.
 *
 * The payload holds the URLs (or a resume cursor) rather than the job carrying them as
 * separate columns, because a Cloudflare chunk of 30 URLs is one indivisible unit of work:
 * the API reports no per-URL status, so the chunk succeeds or fails as a whole.
 */
final class Job {

	/**
	 * @param int                  $id           Row id.
	 * @param string               $driver       Driver id, or "system".
	 * @param string               $action       purge_urls, purge_all or preload.
	 * @param array<string, mixed> $payload      Decoded payload.
	 * @param string               $payload_hash Dedupe key.
	 * @param JobStatus            $status       Lifecycle state.
	 * @param int                  $priority     Lower runs first.
	 * @param int                  $attempts     Attempts already spent.
	 * @param int                  $max_attempts Attempts allowed before dead-lettering.
	 * @param string               $available_at UTC datetime.
	 * @param string|null          $claimed_at   UTC datetime.
	 * @param string|null          $claim_token  Claim token.
	 * @param string               $reason       Who asked for this purge.
	 * @param string|null          $last_error   Last error, or the success summary.
	 * @param string               $created_at   UTC datetime.
	 * @param string               $updated_at   UTC datetime.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $driver,
		public readonly string $action,
		public readonly array $payload,
		public readonly string $payload_hash,
		public readonly JobStatus $status,
		public readonly int $priority,
		public readonly int $attempts,
		public readonly int $max_attempts,
		public readonly string $available_at,
		public readonly ?string $claimed_at,
		public readonly ?string $claim_token,
		public readonly string $reason,
		public readonly ?string $last_error,
		public readonly string $created_at,
		public readonly string $updated_at
	) {}

	/**
	 * Build from a database row.
	 *
	 * @param array<string, mixed>|object $row Raw row.
	 */
	public static function from_row( array|object $row ): self {
		$row = (array) $row;

		$payload = json_decode( (string) ( $row['payload'] ?? '{}' ), true );
		if ( ! is_array( $payload ) ) {
			$payload = [];
		}

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['driver'] ?? '' ),
			(string) ( $row['action'] ?? '' ),
			$payload,
			(string) ( $row['payload_hash'] ?? '' ),
			JobStatus::tryFrom( (string) ( $row['status'] ?? '' ) ) ?? JobStatus::Pending,
			(int) ( $row['priority'] ?? 10 ),
			(int) ( $row['attempts'] ?? 0 ),
			(int) ( $row['max_attempts'] ?? 6 ),
			(string) ( $row['available_at'] ?? '' ),
			isset( $row['claimed_at'] ) ? (string) $row['claimed_at'] : null,
			isset( $row['claim_token'] ) ? (string) $row['claim_token'] : null,
			(string) ( $row['reason'] ?? '' ),
			isset( $row['last_error'] ) ? (string) $row['last_error'] : null,
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	/**
	 * URLs carried by this job.
	 *
	 * @return array<int, string>
	 */
	public function urls(): array {
		$urls = $this->payload['urls'] ?? [];

		return is_array( $urls ) ? array_values( array_filter( array_map( 'strval', $urls ) ) ) : [];
	}

	/**
	 * Redis SCAN continuation cursor, when this job resumes a partial wildcard sweep.
	 */
	public function cursor(): ?string {
		$cursor = $this->payload['cursor'] ?? null;

		return ( null === $cursor || '' === $cursor ) ? null : (string) $cursor;
	}

	/**
	 * Arbitrary metadata carried alongside the payload.
	 *
	 * @return array<string, mixed>
	 */
	public function meta(): array {
		$meta = $this->payload['meta'] ?? [];

		return is_array( $meta ) ? $meta : [];
	}

	public function is_last_attempt(): bool {
		return $this->attempts + 1 >= $this->max_attempts;
	}

	/**
	 * Canonical dedupe hash for a job shape.
	 *
	 * @param string               $driver  Driver id.
	 * @param string               $action  Action.
	 * @param array<string, mixed> $payload Payload.
	 */
	public static function hash( string $driver, string $action, array $payload ): string {
		// Sort URLs so the same set enqueued in a different order still dedupes.
		if ( isset( $payload['urls'] ) && is_array( $payload['urls'] ) ) {
			$payload['urls'] = array_values( $payload['urls'] );
			sort( $payload['urls'] );
		}

		return sha1( $driver . '|' . $action . '|' . (string) wp_json_encode( $payload ) );
	}
}
