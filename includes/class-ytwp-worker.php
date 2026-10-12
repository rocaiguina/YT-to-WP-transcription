<?php
/**
 * The scheduled work: find new uploads, then turn one into a draft per tick.
 *
 * @package YT_To_WP_Transcription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deliberately handles a single video per run. A long episode means a
 * transcript of tens of thousands of words and a model call that can take
 * well over a minute, and WP-Cron runs inside an ordinary PHP request that
 * managed hosts cut off after 30 to 60 seconds. One video per tick keeps
 * each run short enough to finish, and the queue carries the rest forward.
 */
class YTWP_Worker {

	const LOCK           = 'ytwp_worker_lock';
	const LAST_RUN       = 'ytwp_last_run';
	const SCAN_PAGE_SIZE = 50;
	const MAX_ATTEMPTS   = 3;

	/**
	 * Hook the cron jobs.
	 */
	public static function init() {
		add_action( YTWP_CRON_SCAN, array( __CLASS__, 'scan' ) );
		add_action( YTWP_CRON_WORK, array( __CLASS__, 'process_next' ) );
	}

	/**
	 * Note the channel's recent uploads in the queue. Stops at the first page
	 * that adds nothing new, so the routine run costs one API call even on a
	 * channel with thousands of videos.
	 *
	 * @param int $max_pages Safety bound on how far back to walk.
	 * @return array|WP_Error Counts.
	 */
	public static function scan( $max_pages = 2 ) {
		if ( ! YTWP_Settings::get( 'enabled' ) ) {
			return new WP_Error( 'ytwp_disabled', __( 'The plugin is switched off.', 'yt-to-wp-transcription' ) );
		}

		$channel_id = (string) YTWP_Settings::get( 'channel_id', '' );
		if ( '' === $channel_id ) {
			return new WP_Error( 'ytwp_no_channel', __( 'No channel id is saved.', 'yt-to-wp-transcription' ) );
		}

		$added      = 0;
		$seen       = 0;
		$page_token = null;

		for ( $page = 0; $page < max( 1, (int) $max_pages ); $page++ ) {
			$result = YTWP_YouTube::list_uploads( $channel_id, $page_token, self::SCAN_PAGE_SIZE );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$new_on_page = 0;
			foreach ( $result['videos'] as $video ) {
				$seen++;
				if ( YTWP_Store::remember( $video ) ) {
					$added++;
					$new_on_page++;
				}
			}

			$page_token = $result['next_page_token'];

			if ( ! $page_token || 0 === $new_on_page ) {
				break;
			}
		}

		return array(
			'seen'  => $seen,
			'added' => $added,
		);
	}

	/**
	 * Take the next queued video and see it through to a draft.
	 *
	 * @return array|WP_Error Outcome.
	 */
	public static function process_next() {
		if ( ! YTWP_Settings::get( 'enabled' ) ) {
			return new WP_Error( 'ytwp_disabled', __( 'The plugin is switched off.', 'yt-to-wp-transcription' ) );
		}

		// A slow video must not have a second run pile in behind it.
		if ( get_transient( self::LOCK ) ) {
			return new WP_Error( 'ytwp_busy', __( 'Another run is still working.', 'yt-to-wp-transcription' ) );
		}

		$row = YTWP_Store::next_pending();
		if ( ! $row ) {
			return array( 'status' => 'idle' );
		}

		set_transient( self::LOCK, time(), 10 * MINUTE_IN_SECONDS );
		YTWP_Store::set_status( $row->video_id, YTWP_Store::STATUS_WORKING );
		YTWP_Store::bump_attempts( $row->video_id );
		update_option( self::LAST_RUN, time(), false );

		// Hosts that allow it let the model call run to completion; the ones
		// that do not ignore this quietly, which is why the queue exists.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$outcome = self::handle( $row );

		delete_transient( self::LOCK );

		return $outcome;
	}

	/**
	 * Everything that can go wrong for one video, in one place.
	 *
	 * @param object $row Queue row.
	 * @return array|WP_Error
	 */
	private static function handle( $row ) {
		$video_id = $row->video_id;

		$existing = YTWP_Store::existing_post_id( $video_id );
		if ( $existing ) {
			YTWP_Store::set_status(
				$video_id,
				YTWP_Store::STATUS_SKIPPED,
				array(
					'kind'    => 'existing',
					'post_id' => $existing,
					'note'    => __( 'A post for this video already exists.', 'yt-to-wp-transcription' ),
				)
			);
			return array(
				'status'   => 'skipped',
				'video_id' => $video_id,
			);
		}

		$video = YTWP_YouTube::get_video( $video_id );
		if ( is_wp_error( $video ) ) {
			return self::fail( $row, $video );
		}

		$short_max  = (int) YTWP_Settings::get( 'short_max_seconds', 60 );
		$is_short   = $video['duration_seconds'] > 0 && $video['duration_seconds'] <= $short_max;
		$keep_clips = (bool) YTWP_Settings::get( 'publish_short_clips', 1 );

		if ( $is_short && YTWP_Settings::get( 'skip_shorts' ) ) {
			return self::as_clip_or_skip( $row, $video, $keep_clips, 'short', __( 'Shorter than the Shorts threshold.', 'yt-to-wp-transcription' ) );
		}

		$transcript = YTWP_YouTube::get_transcript( $video_id );
		if ( is_wp_error( $transcript ) ) {
			// No caption track is an ordinary outcome for a clip, not a fault
			// in the run; anything else is a real failure worth retrying.
			if ( 'ytwp_no_captions' === $transcript->get_error_code() ) {
				return self::as_clip_or_skip( $row, $video, $keep_clips, 'no-captions', $transcript->get_error_message() );
			}
			return self::fail( $row, $transcript );
		}

		$words = YTWP_YouTube::count_words( $transcript['segments'] );
		$min   = (int) YTWP_Settings::get( 'min_transcript_words', 400 );

		if ( $words < $min ) {
			return self::as_clip_or_skip(
				$row,
				$video,
				$keep_clips,
				'too-brief',
				sprintf(
					/* translators: 1: word count, 2: configured minimum. */
					__( 'Transcript runs %1$d words, below the %2$d-word minimum.', 'yt-to-wp-transcription' ),
					$words,
					$min
				)
			);
		}

		$result = YTWP_Claude::draft(
			$video,
			YTWP_YouTube::to_timestamped_text( $transcript['segments'] ),
			$transcript['automatic']
		);
		if ( is_wp_error( $result ) ) {
			return self::fail( $row, $result );
		}

		$post_id = YTWP_Publisher::create_article( $video, $result['draft'] );
		if ( is_wp_error( $post_id ) ) {
			return self::fail( $row, $post_id );
		}

		$note = '';
		if ( ! empty( $result['draft']['needs_review'] ) ) {
			$note = sprintf(
				/* translators: %d: number of checklist items. */
				_n( '%d item to check before publishing.', '%d items to check before publishing.', count( $result['draft']['needs_review'] ), 'yt-to-wp-transcription' ),
				count( $result['draft']['needs_review'] )
			);
		}

		YTWP_Store::set_status(
			$video_id,
			YTWP_Store::STATUS_DONE,
			array(
				'kind'    => 'article',
				'post_id' => $post_id,
				'note'    => $note,
			)
		);

		return array(
			'status'   => 'done',
			'video_id' => $video_id,
			'post_id'  => $post_id,
		);
	}

	/**
	 * Either publish the video as a bare clip post or record why it was
	 * passed over.
	 *
	 * @param object $row        Queue row.
	 * @param array  $video      Video data.
	 * @param bool   $keep_clips Whether clip posts are wanted.
	 * @param string $kind       Short reason code.
	 * @param string $note       Human-readable reason.
	 * @return array|WP_Error
	 */
	private static function as_clip_or_skip( $row, array $video, $keep_clips, $kind, $note ) {
		if ( ! $keep_clips ) {
			YTWP_Store::set_status(
				$row->video_id,
				YTWP_Store::STATUS_SKIPPED,
				array(
					'kind' => $kind,
					'note' => $note,
				)
			);
			return array(
				'status'   => 'skipped',
				'video_id' => $row->video_id,
			);
		}

		$post_id = YTWP_Publisher::create_clip( $video );
		if ( is_wp_error( $post_id ) ) {
			return self::fail( $row, $post_id );
		}

		YTWP_Store::set_status(
			$row->video_id,
			YTWP_Store::STATUS_DONE,
			array(
				'kind'    => 'clip',
				'post_id' => $post_id,
				'note'    => $note,
			)
		);

		return array(
			'status'   => 'clip',
			'video_id' => $row->video_id,
			'post_id'  => $post_id,
		);
	}

	/**
	 * Record a failure, and stop retrying once a video has used up its
	 * attempts so one broken item cannot block the queue forever.
	 *
	 * @param object   $row   Queue row.
	 * @param WP_Error $error What went wrong.
	 * @return WP_Error
	 */
	private static function fail( $row, WP_Error $error ) {
		$attempts  = (int) $row->attempts + 1;
		$exhausted = $attempts >= self::MAX_ATTEMPTS;

		YTWP_Store::set_status(
			$row->video_id,
			$exhausted ? YTWP_Store::STATUS_ERROR : YTWP_Store::STATUS_PENDING,
			array( 'note' => $error->get_error_message() )
		);

		return $error;
	}

	/**
	 * When the worker last picked something up.
	 *
	 * @return int Unix timestamp, or 0.
	 */
	public static function last_run() {
		return (int) get_option( self::LAST_RUN, 0 );
	}
}
