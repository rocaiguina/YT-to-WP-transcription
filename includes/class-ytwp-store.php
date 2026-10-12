<?php
/**
 * The video queue: one row per upload the plugin has seen.
 *
 * @package YT_To_WP_Transcription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the custom table. A table rather than an option because an active
 * channel can hold thousands of videos, and an option array that size gets
 * read and unserialized on every single request.
 */
class YTWP_Store {

	const STATUS_PENDING = 'pending';
	const STATUS_WORKING = 'working';
	const STATUS_DONE    = 'done';
	const STATUS_SKIPPED = 'skipped';
	const STATUS_ERROR   = 'error';

	const DB_VERSION_OPTION = 'ytwp_db_version';
	const DB_VERSION        = 1;

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ytwp_videos';
	}

	/**
	 * Create or migrate the table.
	 */
	public static function install() {
		global $wpdb;

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			video_id varchar(32) NOT NULL,
			title text NOT NULL,
			published_at datetime NOT NULL,
			duration_seconds int unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			kind varchar(20) NOT NULL DEFAULT '',
			note text NULL,
			post_id bigint(20) unsigned NULL,
			attempts smallint unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (video_id),
			KEY status_published (status, published_at),
			KEY published (published_at)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Record a video the scan just found. Existing rows are left untouched,
	 * so a rescan never resets work already done.
	 *
	 * @param array $video Video data from YTWP_YouTube.
	 * @return bool True when the row was newly inserted.
	 */
	public static function remember( array $video ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT video_id FROM {$table} WHERE video_id = %s", $video['video_id'] ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( $exists ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'video_id'         => $video['video_id'],
				'title'            => $video['title'],
				'published_at'     => gmdate( 'Y-m-d H:i:s', strtotime( $video['published_at'] ) ),
				'duration_seconds' => (int) $video['duration_seconds'],
				'status'           => self::STATUS_PENDING,
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return true;
	}

	/**
	 * The next video to work on: oldest-pending-first would make a new
	 * install start years back, so the newest upload goes first and the
	 * backlog drains behind it.
	 *
	 * @return object|null
	 */
	public static function next_pending() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY published_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_PENDING
			)
		);
	}

	/**
	 * Move a row to a new state.
	 *
	 * @param string $video_id Video id.
	 * @param string $status   One of the STATUS_* constants.
	 * @param array  $extra    Optional kind/note/post_id.
	 */
	public static function set_status( $video_id, $status, array $extra = array() ) {
		global $wpdb;

		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		$fmt  = array( '%s', '%s' );

		if ( isset( $extra['kind'] ) ) {
			$data['kind'] = $extra['kind'];
			$fmt[]        = '%s';
		}
		if ( array_key_exists( 'note', $extra ) ) {
			$data['note'] = is_string( $extra['note'] ) ? $extra['note'] : '';
			$fmt[]        = '%s';
		}
		if ( isset( $extra['post_id'] ) ) {
			$data['post_id'] = (int) $extra['post_id'];
			$fmt[]           = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::table(), $data, array( 'video_id' => $video_id ), $fmt, array( '%s' ) );
	}

	/**
	 * Count one more attempt against a video, so a permanently broken one
	 * stops being retried forever.
	 *
	 * @param string $video_id Video id.
	 */
	public static function bump_attempts( $video_id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE video_id = %s", $video_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Rows for the admin screen.
	 *
	 * @param array $args status, per_page, page.
	 * @return object[]
	 */
	public static function listing( array $args = array() ) {
		global $wpdb;

		$args   = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'per_page' => 25,
				'page'     => 1,
			)
		);
		$table  = self::table();
		$limit  = max( 1, (int) $args['per_page'] );
		$offset = max( 0, ( (int) $args['page'] - 1 ) * $limit );

		if ( $args['status'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY published_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$args['status'],
					$limit,
					$offset
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY published_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit,
				$offset
			)
		);
	}

	/**
	 * How many rows sit in each state.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" );

		$counts = array(
			self::STATUS_PENDING => 0,
			self::STATUS_WORKING => 0,
			self::STATUS_DONE    => 0,
			self::STATUS_SKIPPED => 0,
			self::STATUS_ERROR   => 0,
		);
		foreach ( (array) $rows as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}
		return $counts;
	}

	/**
	 * Put a row back in line, for the retry button.
	 *
	 * @param string $video_id Video id.
	 */
	public static function requeue( $video_id ) {
		self::set_status(
			$video_id,
			self::STATUS_PENDING,
			array(
				'note' => '',
				'kind' => '',
			)
		);
	}

	/**
	 * Find the post that already represents a video, so the plugin never
	 * creates a second one. Checks its own table first, then falls back to
	 * the meta key in case the post was made before the plugin existed.
	 *
	 * @param string $video_id Video id.
	 * @return int Post id, or 0.
	 */
	public static function existing_post_id( $video_id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$known = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_id FROM {$table} WHERE video_id = %s", $video_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( $known && 'trash' !== get_post_status( $known ) ) {
			return $known;
		}

		$found = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => YTWP_Publisher::VIDEO_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $video_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => false,
			)
		);

		return empty( $found ) ? 0 : (int) $found[0];
	}
}
