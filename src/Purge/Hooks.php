<?php
/**
 * WordPress events that invalidate cache.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Purge;

use OhMyCache\Container;
use OhMyCache\Support\Options;
use WP_Comment;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Collects, never purges. Every callback here does nothing but hand URLs to the coordinator,
 * which dispatches once at shutdown.
 */
final class Hooks {

	/** @var array<int, WP_Post> Pre-update posts, keyed by id. */
	private array $before = [];

	public function __construct( private readonly Container $container ) {}

	/**
	 * Wire everything up.
	 */
	public function register(): void {
		// Posts.
		add_action( 'post_updated', [ $this, 'on_post_updated' ], 10, 3 );
		add_action( 'transition_post_status', [ $this, 'on_transition_post_status' ], 20, 3 );

		/*
		 * The block editor saves over REST, and transition_post_status fires before terms and
		 * meta are attached. Enumerating taxonomies at that point yields the previous set, so a
		 * post moved between categories leaves the old category archive stale. Both donor
		 * plugins have this bug. A late save_post plus rest_after_insert_* catches the real
		 * state.
		 */
		add_action( 'save_post', [ $this, 'on_save_post' ], 999, 2 );
		add_action( 'init', [ $this, 'register_rest_hooks' ], 99 );

		/*
		 * deleted_post fires after the row is gone, so get_permalink() returns nonsense there.
		 * Collect while the post still exists.
		 */
		add_action( 'before_delete_post', [ $this, 'on_before_delete_post' ], 10, 2 );

		// Attachments.
		add_action( 'edit_attachment', [ $this, 'on_edit_attachment' ], 100 );
		add_action( 'delete_attachment', [ $this, 'on_edit_attachment' ], 100 );

		// Comments.
		add_action( 'wp_insert_comment', [ $this, 'on_insert_comment' ], 200, 2 );
		add_action( 'transition_comment_status', [ $this, 'on_transition_comment_status' ], 200, 3 );

		// Terms.
		add_action( 'edited_term', [ $this, 'on_term_change' ], 20, 3 );
		add_action( 'created_term', [ $this, 'on_term_change' ], 20, 3 );
		add_action( 'delete_term', [ $this, 'on_term_change' ], 20, 3 );

		// Site-wide changes.
		add_action( 'switch_theme', [ $this, 'on_site_change' ] );
		add_action( 'customize_save_after', [ $this, 'on_site_change' ] );
		add_action( 'wp_update_nav_menu', [ $this, 'on_menu_change' ] );
	}

	/**
	 * Register per-post-type REST hooks once post types exist.
	 */
	public function register_rest_hooks(): void {
		foreach ( get_post_types( [ 'public' => true ], 'names' ) as $post_type ) {
			add_action( "rest_after_insert_{$post_type}", [ $this, 'on_rest_after_insert' ], 10, 1 );
		}
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Capture the pre-update post so a changed permalink purges its old URL too.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $after   Updated post.
	 * @param WP_Post $before  Previous post.
	 */
	public function on_post_updated( int $post_id, $after, $before ): void {
		if ( $before instanceof WP_Post ) {
			$this->before[ $post_id ] = $before;
		}
	}

	/**
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post       The post.
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// Only transitions that change what a visitor can see matter.
		$visible = [ 'publish', 'future', 'private', 'trash' ];

		if ( ! in_array( $new_status, $visible, true ) && ! in_array( $old_status, $visible, true ) ) {
			return;
		}

		$this->collect_post( $post->ID, 'post:' . $new_status, 'trash' === $new_status );
	}

	/**
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    The post.
	 */
	public function on_save_post( $post_id, $post ): void {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		$this->collect_post( (int) $post_id, 'post:save' );
	}

	/**
	 * @param WP_Post $post The post, after REST has written terms and meta.
	 */
	public function on_rest_after_insert( $post ): void {
		if ( $post instanceof WP_Post ) {
			$this->collect_post( $post->ID, 'post:rest' );
		}
	}

	/**
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    The post, still present.
	 */
	public function on_before_delete_post( $post_id, $post = null ): void {
		$this->collect_post( (int) $post_id, 'post:delete', true );
	}

	/**
	 * @param int $post_id Attachment id.
	 */
	public function on_edit_attachment( $post_id ): void {
		if ( ! Options::flag( 'purge.on_attachment_edit', true ) ) {
			return;
		}

		$this->collect_post( (int) $post_id, 'attachment' );
	}

	/**
	 * @param int        $comment_id Comment id.
	 * @param WP_Comment $comment    The comment.
	 */
	public function on_insert_comment( $comment_id, $comment ): void {
		if ( ! $comment instanceof WP_Comment || ! Options::flag( 'purge.on_new_comment', true ) ) {
			return;
		}

		// Only an approved comment changes what a visitor sees.
		if ( '1' !== (string) $comment->comment_approved ) {
			return;
		}

		$this->collect_post( (int) $comment->comment_post_ID, 'comment:new' );
	}

	/**
	 * @param string     $new_status New status.
	 * @param string     $old_status Previous status.
	 * @param WP_Comment $comment    The comment.
	 */
	public function on_transition_comment_status( $new_status, $old_status, $comment ): void {
		if ( ! $comment instanceof WP_Comment || ! Options::flag( 'purge.on_comment_status', true ) ) {
			return;
		}

		// Only a crossing of the approved boundary changes the rendered page.
		if ( 'approved' !== $new_status && 'approved' !== $old_status ) {
			return;
		}

		$this->collect_post( (int) $comment->comment_post_ID, 'comment:' . $new_status );
	}

	/**
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term_change( $term_id, $tt_id, $taxonomy ): void {
		if ( $this->should_skip() || ! Options::flag( 'purge.on_term_change', true ) ) {
			return;
		}

		$collector = $this->container->get( 'collector' );
		$urls      = $collector->for_term( (int) $term_id, (string) $taxonomy );

		if ( $urls ) {
			$this->container->get( 'coordinator' )->add( $urls, 'term:' . $taxonomy );
		}
	}

	/**
	 * A theme switch or a customizer save changes every rendered page.
	 */
	public function on_site_change(): void {
		if ( $this->should_skip() || ! Options::flag( 'purge.on_theme_switch', true ) ) {
			return;
		}

		$this->container->get( 'coordinator' )->add_purge_all( 'site:theme' );
	}

	/**
	 * Menus appear in the chrome of every page.
	 */
	public function on_menu_change(): void {
		if ( $this->should_skip() || ! Options::flag( 'purge.on_menu_change', true ) ) {
			return;
		}

		$this->container->get( 'coordinator' )->add_purge_all( 'site:menu' );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Enumerate a post and hand the URLs to the coordinator.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $reason   Who asked.
	 * @param bool   $deleting Whether the post is going away.
	 */
	private function collect_post( int $post_id, string $reason, bool $deleting = false ): void {
		if ( $this->should_skip() || ! $this->is_purgeable( $post_id ) ) {
			return;
		}

		$collector = $this->container->get( 'collector' );
		$urls      = $collector->for_post( $post_id, $this->before[ $post_id ] ?? null, $deleting );

		if ( $urls ) {
			$this->container->get( 'coordinator' )->add( $urls, $reason );
		}
	}

	/**
	 * Skip states where purging is pointless or actively harmful.
	 */
	private function should_skip(): bool {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return true;
		}

		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether this post can appear on the front end at all.
	 *
	 * @param int $post_id Post id.
	 */
	private function is_purgeable( int $post_id ): bool {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		// Nav menu items and other internal types are never rendered on their own.
		if ( 'nav_menu_item' === $post->post_type ) {
			return false;
		}

		// A post type switched off on the settings screen stops triggering anything by itself.
		if ( ! Options::post_type_enabled( $post->post_type ) ) {
			return false;
		}

		return 'attachment' === $post->post_type || is_post_type_viewable( $post->post_type );
	}
}
