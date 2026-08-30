<?php
/**
 * Declarative hook-to-purge mapping.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Lets another plugin say "when this hook fires, purge these URLs" without writing a callback.
 *
 * The common integration is three lines of glue that everyone writes slightly differently and
 * slightly wrong. Registering the mapping instead means the URLs are collected through the same
 * coordinator, get the same deduplication and the same queue behaviour, and show up on the
 * queue screen with an attributable reason.
 */
final class TriggerRegistry {

	/** @var array<string, array<int, array<string, mixed>>> */
	private array $triggers = [];

	/**
	 * Register a trigger.
	 *
	 * The mapping accepts:
	 *   urls   - callable returning URLs, or a fixed array of URLs
	 *   post   - callable returning a post id, or a fixed post id
	 *   all    - true to purge everything
	 *   reason - label shown on the queue screen
	 *   args   - how many hook arguments to pass through (default 1)
	 *
	 * @param string               $hook    Hook name.
	 * @param array<string, mixed> $mapping Mapping.
	 */
	public function register( string $hook, array $mapping ): void {
		$hook = trim( $hook );

		if ( '' === $hook ) {
			return;
		}

		$mapping = wp_parse_args(
			$mapping,
			[
				'urls'     => null,
				'post'     => null,
				'all'      => false,
				'reason'   => $hook,
				'args'     => 1,
				'priority' => 10,
			]
		);

		$this->triggers[ $hook ][] = $mapping;

		add_action(
			$hook,
			function ( ...$hook_args ) use ( $mapping ): void {
				$this->fire( $mapping, $hook_args );
			},
			(int) $mapping['priority'],
			max( 1, (int) $mapping['args'] )
		);
	}

	/**
	 * Everything registered, for the read-only list on the Advanced tab.
	 *
	 * Seeing what else on the site is asking for purges is half of diagnosing a site that
	 * purges too often.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function all(): array {
		return $this->triggers;
	}

	/**
	 * Resolve a mapping against the hook arguments and stage the purge.
	 *
	 * @param array<string, mixed> $mapping   Mapping.
	 * @param array<int, mixed>    $hook_args Arguments the hook fired with.
	 */
	private function fire( array $mapping, array $hook_args ): void {
		$args = [ 'reason' => (string) $mapping['reason'] ];

		if ( ! empty( $mapping['all'] ) ) {
			Facade::purge_all( $args );

			return;
		}

		if ( null !== $mapping['post'] ) {
			$post_id = is_callable( $mapping['post'] )
				? (int) call_user_func_array( $mapping['post'], $hook_args )
				: (int) $mapping['post'];

			if ( $post_id > 0 ) {
				Facade::purge_post( $post_id, $args );
			}

			return;
		}

		if ( null !== $mapping['urls'] ) {
			$urls = is_callable( $mapping['urls'] )
				? call_user_func_array( $mapping['urls'], $hook_args )
				: $mapping['urls'];

			$urls = array_values( array_filter( array_map( 'strval', (array) $urls ) ) );

			if ( $urls ) {
				Facade::purge_url( $urls, $args );
			}
		}
	}
}
