<?php
/**
 * Queue list table.
 *
 * @package OhMyCache
 */

declare( strict_types = 1 );

namespace OhMyCache\Admin;

use OhMyCache\Queue\Job;
use OhMyCache\Queue\JobStatus;
use OhMyCache\Queue\QueueRepository;
use OhMyCache\Queue\Schema;

defined( 'ABSPATH' ) || exit;

/*
 * WP_List_Table is marked @access private in core and is not autoloaded outside list-table
 * screens, so it has to be pulled in explicitly.
 */
if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The screen that replaces Nginx Helper's log file.
 *
 * Dropping the log file only works if this earns its place, so successful jobs keep their
 * summary until the GC removes them: the Done view is what answers "did my purge actually
 * work?", and the Origin column answers "who asked for it?".
 *
 * Everything rendered here is escaped. last_error in particular holds text that came back from
 * the Cloudflare API, which is third-party content being printed into wp-admin.
 */
final class QueueListTable extends \WP_List_Table {

	private QueueRepository $repository;

	/** @var array<string, int> */
	private array $counts = [];

	/**
	 * @param QueueRepository $repository Queue.
	 */
	public function __construct( QueueRepository $repository ) {
		$this->repository = $repository;

		parent::__construct(
			[
				'singular' => 'job',
				'plural'   => 'oh-my-cache-jobs',
				'ajax'     => false,
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'cb'           => '<input type="checkbox" />',
			'id'           => __( 'ID', 'oh-my-cache' ),
			'driver'       => __( 'Driver', 'oh-my-cache' ),
			'action'       => __( 'Action', 'oh-my-cache' ),
			'reason'       => __( 'Origin', 'oh-my-cache' ),
			'status'       => __( 'Status', 'oh-my-cache' ),
			'attempts'     => __( 'Attempts', 'oh-my-cache' ),
			'available_at' => __( 'Next run', 'oh-my-cache' ),
			'payload'      => __( 'URLs', 'oh-my-cache' ),
			'last_error'   => __( 'Outcome', 'oh-my-cache' ),
		];
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function get_bulk_actions(): array {
		return [
			'run_now' => __( 'Run now', 'oh-my-cache' ),
			'retry'   => __( 'Retry', 'oh-my-cache' ),
			'delete'  => __( 'Delete', 'oh-my-cache' ),
		];
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$this->counts = $this->repository->counts();
		$current      = $this->current_status();

		$views = [];

		$all_label = __( 'All', 'oh-my-cache' );
		$views['all'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
			esc_url( remove_query_arg( 'status' ) ),
			'' === $current ? ' class="current"' : '',
			esc_html( $all_label ),
			esc_html( (string) array_sum( $this->counts ) )
		);

		foreach ( JobStatus::cases() as $status ) {
			$views[ $status->value ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( add_query_arg( 'status', $status->value ) ),
				$current === $status->value ? ' class="current"' : '',
				esc_html( $status->label() ),
				esc_html( (string) ( $this->counts[ $status->value ] ?? 0 ) )
			);
		}

		return $views;
	}

	/**
	 * Load rows.
	 */
	public function prepare_items(): void {
		global $wpdb;

		$this->_column_headers = [ $this->get_columns(), [], [] ];

		$per_page = 50;
		$paged    = max( 1, (int) $this->get_pagenum() );
		$offset   = ( $paged - 1 ) * $per_page;

		$status = $this->current_status();
		$search = $this->current_search();
		$table  = Schema::table();

		/*
		 * The clause opens with a placeholder rather than a literal 1=1 so that $params is never
		 * empty. That means there is exactly one code path and it always goes through prepare(),
		 * instead of a second branch handing raw SQL to get_var() the moment nobody happens to be
		 * filtering.
		 */
		$where  = [ '%d = 1' ];
		$params = [ 1 ];

		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$where[]  = '( payload LIKE %s OR reason LIKE %s )';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$clause = implode( ' AND ', $where );

		// The table name comes from $wpdb->prefix, never from input; every value is a placeholder.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$rows_sql  = "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, [ $per_page, $offset ] ) ), ARRAY_A );

		$this->items = array_map( [ Job::class, 'from_row' ], (array) $rows );

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			]
		);
	}

	/* --------------------------------------------------------------------- */
	/* Columns                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * @param Job $item Job.
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="job[]" value="%d" />', (int) $item->id );
	}

	/**
	 * @param Job $item Job.
	 */
	protected function column_id( Job $item ): string {
		return esc_html( (string) $item->id );
	}

	/**
	 * @param Job $item Job.
	 */
	protected function column_driver( Job $item ): string {
		return esc_html( $item->driver );
	}

	/**
	 * @param Job $item Job.
	 */
	protected function column_action( Job $item ): string {
		return esc_html( $item->action );
	}

	/**
	 * @param Job $item Job.
	 */
	protected function column_reason( Job $item ): string {
		return '' === $item->reason
			? '<span aria-hidden="true">&mdash;</span>'
			: esc_html( $item->reason );
	}

	/**
	 * @param Job $item Job.
	 */
	protected function column_status( Job $item ): string {
		return sprintf(
			'<span class="oh-my-cache-status oh-my-cache-status--%s">%s</span>',
			esc_attr( $item->status->tone() ),
			esc_html( $item->status->label() )
		);
	}

	/**
	 * @param Job $item Job.
	 */
	protected function column_attempts( Job $item ): string {
		return esc_html( sprintf( '%d / %d', $item->attempts, $item->max_attempts ) );
	}

	/**
	 * @param Job $item Job.
	 */
	protected function column_available_at( Job $item ): string {
		if ( JobStatus::Pending !== $item->status ) {
			return '<span aria-hidden="true">&mdash;</span>';
		}

		$timestamp = (int) strtotime( $item->available_at . ' UTC' );

		if ( $timestamp <= time() ) {
			return esc_html__( 'Due now', 'oh-my-cache' );
		}

		return esc_html(
			sprintf(
				/* translators: %s: human readable time difference. */
				__( 'in %s', 'oh-my-cache' ),
				human_time_diff( time(), $timestamp )
			)
		);
	}

	/**
	 * @param Job $item Job.
	 */
	protected function column_payload( Job $item ): string {
		$urls = $item->urls();

		if ( ! $urls ) {
			return '<span aria-hidden="true">&mdash;</span>';
		}

		$summary = sprintf(
			/* translators: %d: number of URLs. */
			_n( '%d URL', '%d URLs', count( $urls ), 'oh-my-cache' ),
			count( $urls )
		);

		return sprintf(
			'<details><summary>%s</summary><div class="oh-my-cache-urls">%s</div></details>',
			esc_html( $summary ),
			esc_html( implode( "\n", $urls ) )
		);
	}

	/**
	 * The outcome column.
	 *
	 * This holds text that came back from the Cloudflare API, so it is third-party content and
	 * escaping it is not optional.
	 *
	 * @param Job $item Job.
	 */
	protected function column_last_error( Job $item ): string {
		$text = (string) $item->last_error;

		if ( '' === $text ) {
			return '<span aria-hidden="true">&mdash;</span>';
		}

		if ( mb_strlen( $text ) <= 120 ) {
			return esc_html( $text );
		}

		return sprintf(
			'<details><summary>%s</summary><div class="oh-my-cache-error">%s</div></details>',
			esc_html( mb_substr( $text, 0, 120 ) . '…' ),
			esc_html( $text )
		);
	}

	/**
	 * @param Job    $item        Job.
	 * @param string $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		return '';
	}

	public function no_items(): void {
		esc_html_e( 'Nothing in the queue. Purges are running inline, which is what you want.', 'oh-my-cache' );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Currently selected status filter.
	 */
	private function current_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		return in_array( $status, JobStatus::values(), true ) ? $status : '';
	}

	/**
	 * Current search term.
	 */
	private function current_search(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
	}
}
