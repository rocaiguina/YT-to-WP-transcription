<?php
/**
 * Stored configuration and the settings screen.
 *
 * @package YT_To_WP_Transcription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the single option array the rest of the plugin reads from.
 */
class YTWP_Settings {

	const OPTION = 'ytwp_settings';
	const GROUP  = 'ytwp_settings_group';

	/**
	 * Keys whose values are secrets: never echoed back into the form, and
	 * redacted in any debug output.
	 *
	 * @var string[]
	 */
	const SECRET_KEYS = array(
		'anthropic_api_key',
		'youtube_api_key',
		'google_client_secret',
	);

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'channel_id'              => '',
			// More channels the same account manages, each with its own
			// category and its own YouTube connection: rows of
			// {channel_id, post_category, refresh_token}.
			'extra_channels'          => array(),
			'anthropic_api_key'       => '',
			'anthropic_model'         => 'claude-haiku-4-5',
			'anthropic_workspace_id'  => '',
			'youtube_api_key'         => '',
			'google_client_id'        => '',
			'google_client_secret'    => '',
			'google_refresh_token'    => '',
			'caption_language'        => 'es',
			'post_category'           => 0,
			'post_author'             => 0,
			'earliest_video_date'     => '',
			'min_transcript_words'    => 400,
			'min_duration_minutes'    => 3,
			'max_duration_minutes'    => 0,
			'publish_short_clips'     => 1,
			'skip_guest_episodes'     => 1,
			'enabled'                 => 0,
			'acf_body_field'          => '',
			'acf_summary_field'       => '',
			'acf_video_field'         => '',
			'acf_sources_label_field' => '',
			'acf_source_link_fields'  => '',
			'editorial_notes'         => '',
			// These appear inside the published post, so they belong to the
			// publication's language, not to the WordPress admin locale.
			'label_key_points'        => '',
			'label_sources'           => '',
			'label_watch'             => '',
		);
	}

	/**
	 * Wording for the headings that appear inside published posts, for the
	 * languages shipped with the plugin. The caption language is what the
	 * post will be written in, so it picks these; anything not listed falls
	 * back to English and can be overwritten on the settings screen.
	 *
	 * @param string $language Two-letter language code.
	 * @return array<string,string>
	 */
	public static function default_labels( $language = 'en' ) {
		$packs = array(
			'en' => array(
				'label_key_points' => 'The key points',
				'label_sources'    => 'Sources',
				'label_watch'      => 'Watch the full video:',
			),
			'es' => array(
				'label_key_points' => 'Lo importante',
				'label_sources'    => 'Fuentes',
				'label_watch'      => 'Mira el video completo:',
			),
			'pt' => array(
				'label_key_points' => 'O essencial',
				'label_sources'    => 'Fontes',
				'label_watch'      => 'Assista ao vídeo completo:',
			),
			'fr' => array(
				'label_key_points' => 'L\'essentiel',
				'label_sources'    => 'Sources',
				'label_watch'      => 'Regarder la vidéo complète :',
			),
		);

		$code = strtolower( substr( (string) $language, 0, 2 ) );

		return isset( $packs[ $code ] ) ? $packs[ $code ] : $packs['en'];
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$all = wp_parse_args( $saved, self::defaults() );

		// A heading the admin never set follows the caption language, so a
		// Spanish channel does not end up with English headings inside
		// Spanish articles.
		foreach ( self::default_labels( $all['caption_language'] ) as $key => $text ) {
			if ( '' === trim( (string) $all[ $key ] ) ) {
				$all[ $key ] = $text;
			}
		}

		return $all;
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( ! array_key_exists( $key, $all ) || '' === $all[ $key ] ) {
			return null === $default ? ( self::defaults()[ $key ] ?? null ) : $default;
		}
		return $all[ $key ];
	}

	/**
	 * Every channel the plugin watches. The main one comes from the original
	 * single-channel settings, so a site that never adds another keeps
	 * working exactly as before. Keyed by channel id.
	 *
	 * @return array<string,array{channel_id:string,post_category:int,refresh_token:string,main:bool}>
	 */
	public static function channels() {
		$all      = self::all();
		$channels = array();

		$main_id = trim( (string) $all['channel_id'] );
		if ( '' !== $main_id ) {
			$channels[ $main_id ] = array(
				'channel_id'    => $main_id,
				'post_category' => (int) $all['post_category'],
				'refresh_token' => (string) $all['google_refresh_token'],
				'main'          => true,
			);
		}

		foreach ( (array) $all['extra_channels'] as $row ) {
			$id = isset( $row['channel_id'] ) ? trim( (string) $row['channel_id'] ) : '';
			if ( '' === $id || isset( $channels[ $id ] ) ) {
				continue;
			}
			$channels[ $id ] = array(
				'channel_id'    => $id,
				'post_category' => isset( $row['post_category'] ) ? (int) $row['post_category'] : 0,
				'refresh_token' => isset( $row['refresh_token'] ) ? (string) $row['refresh_token'] : '',
				'main'          => false,
			);
		}

		return $channels;
	}

	/**
	 * One channel's settings. Videos queued before channels existed carry no
	 * channel id, so an empty or unknown id means the main channel.
	 *
	 * @param string $channel_id Channel id.
	 * @return array|null
	 */
	public static function channel( $channel_id ) {
		$channels = self::channels();
		$id       = trim( (string) $channel_id );

		if ( '' !== $id && isset( $channels[ $id ] ) ) {
			return $channels[ $id ];
		}

		foreach ( $channels as $channel ) {
			if ( $channel['main'] ) {
				return $channel;
			}
		}

		return null;
	}

	/**
	 * Persist a subset of settings without disturbing the rest. Used by the
	 * OAuth flow to drop in the refresh token it just obtained.
	 *
	 * @param array $values Partial settings.
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}

	/**
	 * Everything the worker needs, and whether each one is present. Keyed so
	 * the admin screens can name what is missing instead of saying only that
	 * something is.
	 *
	 * @return array<string,array{label:string,ready:bool,hint:string}>
	 */
	public static function readiness() {
		$all = self::all();

		return array(
			'channel_id'           => array(
				'label' => __( 'Channel id', 'yt-to-wp-transcription' ),
				'ready' => '' !== trim( (string) $all['channel_id'] ),
				'hint'  => __( 'Settings screen, Channel section.', 'yt-to-wp-transcription' ),
			),
			'youtube_api_key'      => array(
				'label' => __( 'YouTube API key', 'yt-to-wp-transcription' ),
				'ready' => '' !== trim( (string) $all['youtube_api_key'] ),
				'hint'  => __( 'Settings screen, API keys section.', 'yt-to-wp-transcription' ),
			),
			'anthropic_api_key'    => array(
				'label' => __( 'Anthropic API key', 'yt-to-wp-transcription' ),
				'ready' => '' !== trim( (string) $all['anthropic_api_key'] ),
				'hint'  => __( 'Settings screen, API keys section.', 'yt-to-wp-transcription' ),
			),
			'google_client_id'     => array(
				'label' => __( 'Google client id', 'yt-to-wp-transcription' ),
				'ready' => '' !== trim( (string) $all['google_client_id'] ),
				'hint'  => __( 'Settings screen, YouTube account section.', 'yt-to-wp-transcription' ),
			),
			'google_client_secret' => array(
				'label' => __( 'Google client secret', 'yt-to-wp-transcription' ),
				'ready' => '' !== trim( (string) $all['google_client_secret'] ),
				'hint'  => __( 'Settings screen, YouTube account section.', 'yt-to-wp-transcription' ),
			),
			'google_refresh_token' => array(
				'label' => __( 'YouTube connection', 'yt-to-wp-transcription' ),
				'ready' => '' !== trim( (string) $all['google_refresh_token'] ),
				'hint'  => __( 'Press "Connect to YouTube" on the settings screen and sign in with the account that owns the channel.', 'yt-to-wp-transcription' ),
			),
		);
	}

	/**
	 * The labels of whatever is still missing.
	 *
	 * @return string[]
	 */
	public static function missing() {
		$missing = array();
		foreach ( self::readiness() as $item ) {
			if ( ! $item['ready'] ) {
				$missing[] = $item['label'];
			}
		}
		return $missing;
	}

	/**
	 * True when every credential the worker needs is present.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return array() === self::missing();
	}

	/**
	 * The ACF source-link field names, as an array.
	 *
	 * @return string[]
	 */
	public static function source_link_fields() {
		$raw = (string) self::get( 'acf_source_link_fields', '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	/**
	 * Register the option and its sanitizer.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Clean every submitted value, and keep a stored secret when the field
	 * comes back blank so saving the form never wipes a key by accident.
	 *
	 * @param mixed $input Raw form values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		$clean   = $current;

		if ( ! is_array( $input ) ) {
			return $clean;
		}

		$text_keys = array(
			'channel_id',
			'anthropic_model',
			'anthropic_workspace_id',
			'google_client_id',
			'caption_language',
			'acf_body_field',
			'acf_summary_field',
			'acf_video_field',
			'acf_sources_label_field',
			'acf_source_link_fields',
			'earliest_video_date',
			'label_key_points',
			'label_sources',
			'label_watch',
		);
		foreach ( $text_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
			}
		}

		foreach ( self::SECRET_KEYS as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$value = trim( sanitize_text_field( wp_unslash( $input[ $key ] ) ) );
			// An empty box means "leave the saved key alone", not "erase it".
			if ( '' !== $value ) {
				$clean[ $key ] = $value;
			}
		}

		// register_setting() hooks this callback onto sanitize_option_{option},
		// which WordPress runs on every write to the option — not just on the
		// settings form. The refresh token has no form field, so without this
		// it was dropped the moment the OAuth callback tried to store it, and
		// the connection silently never completed.
		if ( array_key_exists( 'google_refresh_token', $input ) ) {
			$clean['google_refresh_token'] = trim( sanitize_text_field( wp_unslash( $input['google_refresh_token'] ) ) );
		}

		if ( isset( $input['extra_channels'] ) && is_array( $input['extra_channels'] ) ) {
			$clean['extra_channels'] = self::sanitize_extra_channels( $input['extra_channels'], $current['extra_channels'] );
		}

		$int_keys = array( 'post_category', 'post_author', 'min_transcript_words', 'min_duration_minutes', 'max_duration_minutes' );
		foreach ( $int_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = absint( $input[ $key ] );
			}
		}

		foreach ( array( 'publish_short_clips', 'skip_guest_episodes', 'enabled' ) as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		if ( isset( $input['editorial_notes'] ) ) {
			$clean['editorial_notes'] = sanitize_textarea_field( wp_unslash( $input['editorial_notes'] ) );
		}

		if ( $clean['min_transcript_words'] < 1 ) {
			$clean['min_transcript_words'] = self::defaults()['min_transcript_words'];
		}

		return $clean;
	}

	/**
	 * Clean the extra channel rows. The form has no field for a channel's
	 * refresh token, so a row coming from the form keeps the token already
	 * stored for that channel; a row written by the OAuth callback carries
	 * its new token and that one wins. Blank rows are dropped.
	 *
	 * @param array $rows    Submitted rows.
	 * @param mixed $current Rows stored now.
	 * @return array
	 */
	private static function sanitize_extra_channels( array $rows, $current ) {
		$stored_tokens = array();
		foreach ( (array) $current as $row ) {
			if ( ! empty( $row['channel_id'] ) && ! empty( $row['refresh_token'] ) ) {
				$stored_tokens[ $row['channel_id'] ] = $row['refresh_token'];
			}
		}

		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = isset( $row['channel_id'] ) ? trim( sanitize_text_field( wp_unslash( $row['channel_id'] ) ) ) : '';
			if ( '' === $id ) {
				continue;
			}

			if ( array_key_exists( 'refresh_token', $row ) ) {
				$token = trim( sanitize_text_field( wp_unslash( $row['refresh_token'] ) ) );
			} else {
				$token = isset( $stored_tokens[ $id ] ) ? $stored_tokens[ $id ] : '';
			}

			$clean[] = array(
				'channel_id'    => $id,
				'post_category' => isset( $row['post_category'] ) ? absint( $row['post_category'] ) : 0,
				'refresh_token' => $token,
			);
		}

		return $clean;
	}
}
