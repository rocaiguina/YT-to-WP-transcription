<?php
/**
 * Talks to the YouTube Data API.
 *
 * @package YT_To_WP_Transcription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Listing a channel's uploads needs only an API key. Downloading a caption
 * track needs an owner's access token, which YTWP_OAuth supplies.
 */
class YTWP_YouTube {

	const API_BASE = 'https://www.googleapis.com/youtube/v3';

	/**
	 * A channel's uploads live in a playlist whose id is the channel id with
	 * its UC prefix swapped for UU. Using that saves a lookup call.
	 *
	 * @param string $channel_id Channel id.
	 * @return string
	 */
	public static function uploads_playlist_id( $channel_id ) {
		return 'UU' . substr( $channel_id, 2 );
	}

	/**
	 * ISO 8601 duration to seconds.
	 *
	 * @param string $iso Duration such as PT22M30S.
	 * @return int
	 */
	public static function duration_to_seconds( $iso ) {
		if ( ! preg_match( '/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', (string) $iso, $m ) ) {
			return 0;
		}
		$h = isset( $m[1] ) && '' !== $m[1] ? (int) $m[1] : 0;
		$i = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
		$s = isset( $m[3] ) && '' !== $m[3] ? (int) $m[3] : 0;
		return ( $h * HOUR_IN_SECONDS ) + ( $i * MINUTE_IN_SECONDS ) + $s;
	}

	/**
	 * Who the configured channel id actually belongs to. Two channels can
	 * carry the same name, so the upload count is what tells a main channel
	 * apart from a near-empty namesake.
	 *
	 * @param string $channel_id Channel id.
	 * @return array|WP_Error {title, videos, custom_url}
	 */
	public static function describe_channel( $channel_id ) {
		$data = self::get(
			'channels',
			array(
				'part' => 'snippet,statistics',
				'id'   => $channel_id,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['items'][0] ) ) {
			return new WP_Error(
				'ytwp_channel_not_found',
				__( 'No channel has that id. Check it in YouTube Studio under Settings, Channel, Advanced settings.', 'yt-to-wp-transcription' )
			);
		}

		$item = $data['items'][0];

		return array(
			'title'      => isset( $item['snippet']['title'] ) ? $item['snippet']['title'] : '',
			'custom_url' => isset( $item['snippet']['customUrl'] ) ? $item['snippet']['customUrl'] : '',
			'videos'     => isset( $item['statistics']['videoCount'] ) ? (int) $item['statistics']['videoCount'] : 0,
		);
	}

	/**
	 * One page of the channel's uploads, newest first, with the duration and
	 * description each video needs later.
	 *
	 * @param string      $channel_id Channel id.
	 * @param string|null $page_token Cursor from a previous call.
	 * @param int         $per_page   How many to ask for.
	 * @return array|WP_Error {videos: array[], next_page_token: string|null}
	 */
	public static function list_uploads( $channel_id, $page_token = null, $per_page = 50 ) {
		$args = array(
			'part'       => 'snippet',
			'playlistId' => self::uploads_playlist_id( $channel_id ),
			'maxResults' => max( 1, min( 50, (int) $per_page ) ),
		);
		if ( $page_token ) {
			$args['pageToken'] = $page_token;
		}

		$page = self::get( 'playlistItems', $args );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$items = isset( $page['items'] ) ? $page['items'] : array();
		if ( empty( $items ) ) {
			return array(
				'videos'          => array(),
				'next_page_token' => null,
			);
		}

		$ids = array();
		foreach ( $items as $item ) {
			if ( isset( $item['snippet']['resourceId']['videoId'] ) ) {
				$ids[] = $item['snippet']['resourceId']['videoId'];
			}
		}

		$details = self::get(
			'videos',
			array(
				'part' => 'snippet,contentDetails',
				'id'   => implode( ',', $ids ),
			)
		);
		if ( is_wp_error( $details ) ) {
			return $details;
		}

		$videos = array();
		foreach ( ( isset( $details['items'] ) ? $details['items'] : array() ) as $video ) {
			$videos[] = self::shape( $video );
		}

		return array(
			'videos'          => $videos,
			'next_page_token' => isset( $page['nextPageToken'] ) ? $page['nextPageToken'] : null,
		);
	}

	/**
	 * Everything the rest of the plugin needs about one video.
	 *
	 * @param string $video_id Video id.
	 * @return array|WP_Error
	 */
	public static function get_video( $video_id ) {
		$details = self::get(
			'videos',
			array(
				'part' => 'snippet,contentDetails',
				'id'   => $video_id,
			)
		);
		if ( is_wp_error( $details ) ) {
			return $details;
		}

		if ( empty( $details['items'][0] ) ) {
			return new WP_Error(
				'ytwp_video_not_found',
				sprintf(
					/* translators: %s: YouTube video id. */
					__( 'Video %s was not found or is not public.', 'yt-to-wp-transcription' ),
					$video_id
				)
			);
		}

		return self::shape( $details['items'][0] );
	}

	/**
	 * The caption track as timed segments. Prefers a hand-uploaded track in
	 * the configured language, since that one is already corrected, and
	 * falls back to the automatic one.
	 *
	 * @param string $video_id Video id.
	 * @return array|WP_Error {automatic: bool, segments: array[]}
	 */
	public static function get_transcript( $video_id ) {
		$token = YTWP_OAuth::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$list = self::get(
			'captions',
			array(
				'part'    => 'snippet',
				'videoId' => $video_id,
			),
			$token
		);
		if ( is_wp_error( $list ) ) {
			return $list;
		}

		$tracks = isset( $list['items'] ) ? $list['items'] : array();
		if ( empty( $tracks ) ) {
			return new WP_Error(
				'ytwp_no_captions',
				__( 'This video has no caption track.', 'yt-to-wp-transcription' )
			);
		}

		$language = (string) YTWP_Settings::get( 'caption_language', 'es' );
		$chosen   = null;
		foreach ( $tracks as $track ) {
			$lang = isset( $track['snippet']['language'] ) ? $track['snippet']['language'] : '';
			if ( 0 !== strpos( $lang, $language ) ) {
				continue;
			}
			$is_auto = isset( $track['snippet']['trackKind'] ) && 'asr' === $track['snippet']['trackKind'];
			if ( ! $is_auto ) {
				$chosen = $track;
				break;
			}
			if ( null === $chosen ) {
				$chosen = $track;
			}
		}
		if ( null === $chosen ) {
			$chosen = $tracks[0];
		}

		$srt = self::download_caption( $chosen['id'], $token );
		if ( is_wp_error( $srt ) ) {
			return $srt;
		}

		return array(
			'automatic' => isset( $chosen['snippet']['trackKind'] ) && 'asr' === $chosen['snippet']['trackKind'],
			'segments'  => self::parse_srt( $srt ),
		);
	}

	/**
	 * Fetch one caption track as SubRip text.
	 *
	 * @param string $caption_id Caption track id.
	 * @param string $token      OAuth access token.
	 * @return string|WP_Error
	 */
	private static function download_caption( $caption_id, $token ) {
		$response = wp_remote_get(
			self::API_BASE . '/captions/' . rawurlencode( $caption_id ) . '?tfmt=srt',
			array(
				'timeout' => 60,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'ytwp_caption_download_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'YouTube refused the caption download (HTTP %d). The connected account must own the channel.', 'yt-to-wp-transcription' ),
					$code
				)
			);
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * SubRip text to [{start, text}].
	 *
	 * @param string $srt Caption file contents.
	 * @return array[]
	 */
	public static function parse_srt( $srt ) {
		$segments = array();

		foreach ( preg_split( '/\R\s*\R/', trim( (string) $srt ) ) as $block ) {
			$lines  = preg_split( '/\R/', trim( $block ) );
			$timing = null;
			$index  = 0;

			foreach ( $lines as $i => $line ) {
				if ( false !== strpos( $line, '-->' ) ) {
					$timing = $line;
					$index  = $i;
					break;
				}
			}
			if ( null === $timing ) {
				continue;
			}

			$text = trim(
				preg_replace(
					'/\s+/',
					' ',
					wp_strip_all_tags( implode( ' ', array_slice( $lines, $index + 1 ) ) )
				)
			);
			if ( '' === $text ) {
				continue;
			}

			$segments[] = array(
				'start' => self::srt_time_to_seconds( strtok( $timing, '-' ) ),
				'text'  => $text,
			);
		}

		return $segments;
	}

	/**
	 * "00:03:07,120" to 187.
	 *
	 * @param string $stamp Timestamp.
	 * @return int
	 */
	private static function srt_time_to_seconds( $stamp ) {
		if ( ! preg_match( '/(\d+):(\d+):(\d+)[,.](\d+)/', (string) $stamp, $m ) ) {
			return 0;
		}
		return ( (int) $m[1] * HOUR_IN_SECONDS ) + ( (int) $m[2] * MINUTE_IN_SECONDS ) + (int) $m[3];
	}

	/**
	 * Group segments into timestamped blocks. Handing the model text that is
	 * already marked every couple of minutes lets it label sections with real
	 * timestamps instead of inventing them.
	 *
	 * @param array[] $segments      Caption segments.
	 * @param int     $block_seconds Block length.
	 * @return string
	 */
	public static function to_timestamped_text( array $segments, $block_seconds = 120 ) {
		if ( empty( $segments ) ) {
			return '';
		}

		$blocks = array();
		$start  = $segments[0]['start'];
		$buffer = array();

		foreach ( $segments as $segment ) {
			if ( ( $segment['start'] - $start ) >= $block_seconds && ! empty( $buffer ) ) {
				$blocks[] = self::format_timestamp( $start ) . ' ' . implode( ' ', $buffer );
				$buffer   = array();
				$start    = $segment['start'];
			}
			$buffer[] = $segment['text'];
		}
		if ( ! empty( $buffer ) ) {
			$blocks[] = self::format_timestamp( $start ) . ' ' . implode( ' ', $buffer );
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Seconds to "[MM:SS]".
	 *
	 * @param int $seconds Offset.
	 * @return string
	 */
	public static function format_timestamp( $seconds ) {
		// Caption offsets arrive as floats from some sources, and the
		// modulo operator converting one implicitly is deprecated from PHP
		// 8.1 — which would fill the site's error log on every run.
		$seconds = (int) $seconds;

		return sprintf( '[%02d:%02d]', intdiv( $seconds, 60 ), $seconds % 60 );
	}

	/**
	 * Word count across caption segments.
	 *
	 * @param array[] $segments Caption segments.
	 * @return int
	 */
	public static function count_words( array $segments ) {
		$total = 0;
		foreach ( $segments as $segment ) {
			$total += str_word_count( $segment['text'], 0, 'áéíóúüñÁÉÍÓÚÜÑ' );
		}
		return $total;
	}

	/**
	 * Normalize an API video object.
	 *
	 * @param array $video Raw API item.
	 * @return array
	 */
	private static function shape( array $video ) {
		$seconds = self::duration_to_seconds( isset( $video['contentDetails']['duration'] ) ? $video['contentDetails']['duration'] : '' );

		return array(
			'video_id'         => $video['id'],
			'title'            => isset( $video['snippet']['title'] ) ? $video['snippet']['title'] : '',
			'description'      => isset( $video['snippet']['description'] ) ? $video['snippet']['description'] : '',
			'published_at'     => isset( $video['snippet']['publishedAt'] ) ? $video['snippet']['publishedAt'] : '',
			'duration_seconds' => $seconds,
			'duration_iso'     => isset( $video['contentDetails']['duration'] ) ? $video['contentDetails']['duration'] : '',
		);
	}

	/**
	 * A GET against the Data API, authenticated by key or bearer token.
	 *
	 * @param string      $path  Endpoint such as 'videos'.
	 * @param array       $args  Query arguments.
	 * @param string|null $token OAuth token; falls back to the API key.
	 * @return array|WP_Error
	 */
	private static function get( $path, array $args, $token = null ) {
		$headers = array();

		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		} else {
			$key = (string) YTWP_Settings::get( 'youtube_api_key', '' );
			if ( '' === $key ) {
				return new WP_Error(
					'ytwp_missing_api_key',
					__( 'No YouTube API key is saved.', 'yt-to-wp-transcription' )
				);
			}
			$args['key'] = $key;
		}

		$response = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', $args ), self::API_BASE . '/' . $path ),
			array(
				'timeout' => 30,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$detail = isset( $body['error']['message'] ) ? $body['error']['message'] : '';
			return new WP_Error(
				'ytwp_youtube_error',
				sprintf(
					/* translators: 1: endpoint name, 2: HTTP status, 3: error message. */
					__( 'YouTube %1$s returned HTTP %2$d: %3$s', 'yt-to-wp-transcription' ),
					$path,
					$code,
					wp_strip_all_tags( $detail )
				)
			);
		}

		return is_array( $body ) ? $body : array();
	}
}
