<?php
/**
 * Job lifecycle states.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * pending -> claimed -> done, or back to pending with an incremented attempt, or dead.
 */
enum JobStatus: string {

	case Pending = 'pending';
	case Claimed = 'claimed';
	case Done    = 'done';
	case Dead    = 'dead';

	/**
	 * Human label. Called from admin screens only, so translating here is safe.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Pending => __( 'Pending', 'oh-my-cache' ),
			self::Claimed => __( 'Running', 'oh-my-cache' ),
			self::Done    => __( 'Done', 'oh-my-cache' ),
			self::Dead    => __( 'Dead', 'oh-my-cache' ),
		};
	}

	/**
	 * CSS modifier used by the queue list table badge.
	 */
	public function tone(): string {
		return match ( $this ) {
			self::Pending => 'info',
			self::Claimed => 'warning',
			self::Done    => 'success',
			self::Dead    => 'error',
		};
	}

	/**
	 * All values, for sanitizing a request parameter.
	 *
	 * @return array<int, string>
	 */
	public static function values(): array {
		return array_map( static fn ( self $case ): string => $case->value, self::cases() );
	}
}
