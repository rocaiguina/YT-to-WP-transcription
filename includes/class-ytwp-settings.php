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
			'anthropic_api_key'       => '',
			'anthropic_model'         => 'claude-haiku-4-5',
			'youtube_api_key'         => '',
			'google_client_id'        => '',
			'google_client_secret'    => '',
			'google_refresh_token'    => '',
			'caption_language'        => 'es',
			'post_category'           => 0,
			'post_author'             => 0,
			'min_transcript_words'    => 400,
			'skip_shorts'             => 1,
			'short_max_seconds'       => 60,
			'publish_short_clips'     => 1,
			'enabled'                 => 0,
			'acf_body_field'          => '',
			'acf_summary_field'       => '',
			'acf_video_field'         => '',
			'acf_sources_label_field' => '',
			'acf_source_link_fields'  => '',
			'editorial_notes'         => '',
		);
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
		return wp_parse_args( $saved, self::defaults() );
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
	 * Persist a subset of settings without disturbing the rest. Used by the
	 * OAuth flow to drop in the refresh token it just obtained.
	 *
	 * @param array $values Partial settings.
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}

	/**
	 * True when every credential the worker needs is present.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		foreach ( array( 'channel_id', 'anthropic_api_key', 'youtube_api_key' ) as $key ) {
			if ( empty( self::get( $key ) ) ) {
				return false;
			}
		}
		return ! empty( self::get( 'google_refresh_token' ) );
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
			'google_client_id',
			'caption_language',
			'acf_body_field',
			'acf_summary_field',
			'acf_video_field',
			'acf_sources_label_field',
			'acf_source_link_fields',
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

		$int_keys = array( 'post_category', 'post_author', 'min_transcript_words', 'short_max_seconds' );
		foreach ( $int_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = absint( $input[ $key ] );
			}
		}

		foreach ( array( 'skip_shorts', 'publish_short_clips', 'enabled' ) as $key ) {
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
}
