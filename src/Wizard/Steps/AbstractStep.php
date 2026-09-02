<?php
/**
 * Shared wizard step behaviour.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Wizard\Steps;

use OhMyCache\Cloudflare\CacheRules;
use OhMyCache\Cloudflare\Client;
use OhMyCache\Cloudflare\Credentials;
use OhMyCache\Cloudflare\ZoneResolver;
use OhMyCache\Cloudflare\ZoneSettings;
use OhMyCache\Container;
use OhMyCache\Support\Options;
use OhMyCache\Wizard\StepInterface;
use OhMyCache\Wizard\StepResult;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers every step wants.
 */
abstract class AbstractStep implements StepInterface {

	public function __construct( protected readonly Container $container ) {}

	public function revert(): StepResult {
		return StepResult::success( __( 'There is nothing to undo here.', 'oh-my-cache' ) );
	}

	public function primary_label(): string {
		return __( 'Test and continue', 'oh-my-cache' );
	}

	public function can_skip(): bool {
		return false;
	}

	protected function client(): Client {
		return new Client( 15.0 );
	}

	protected function zone_resolver(): ZoneResolver {
		return new ZoneResolver( $this->client() );
	}

	protected function cache_rules(): CacheRules {
		return new CacheRules( $this->client() );
	}

	protected function zone_settings(): ZoneSettings {
		return new ZoneSettings( $this->client() );
	}

	protected function zone_id(): string {
		return Credentials::zone_id();
	}

	/**
	 * Render a paragraph of plain explanation.
	 *
	 * @param string $text Text.
	 */
	protected function paragraph( string $text ): void {
		printf( '<p>%s</p>', esc_html( $text ) );
	}

	/**
	 * Render a labelled read-only value.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	protected function row( string $label, string $value ): void {
		printf( '<p><strong>%s</strong> %s</p>', esc_html( $label ), esc_html( $value ) );
	}

	protected function table_open(): void {
		echo '<table class="form-table" role="presentation"><tbody>';
	}

	protected function table_close(): void {
		echo '</tbody></table>';
	}

	/**
	 * Say what the test will check before it runs.
	 *
	 * Telling somebody what is about to be verified turns a test button from a formality into
	 * something they can reason about when it fails.
	 *
	 * @param array<int, string> $checks What the test does.
	 */
	protected function test_summary( array $checks ): void {
		printf(
			'<div class="notice notice-info inline"><p><strong>%s</strong></p><ul class="oh-my-cache-checklist">',
			esc_html__( 'Before continuing, this is checked:', 'oh-my-cache' )
		);

		foreach ( $checks as $check ) {
			printf( '<li>%s</li>', esc_html( $check ) );
		}

		echo '</ul></div>';
	}

	/**
	 * Read a settings value.
	 *
	 * @param string $path          Dot path.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	protected function setting( string $path, mixed $default_value = null ): mixed {
		return Options::get( $path, $default_value );
	}
}
