<?php
/**
 * Google OAuth, used only to read the channel's own caption tracks.
 *
 * @package YT_To_WP_Transcription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Downloading a caption track is an owner-only operation, so an API key is
 * not enough. This walks the consent screen once and stores the refresh
 * token; after that the plugin mints short-lived access tokens on its own.
 */
class YTWP_OAuth {

	const AUTH_URL      = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
	const SCOPE         = 'https://www.googleapis.com/auth/youtube.force-ssl';
	const STATE_OPTION  = 'ytwp_oauth_state';
	const ACCESS_CACHE  = 'ytwp_access_token';

	/**
	 * Register the admin-post endpoints that start and finish the flow.
	 */
	public static function init() {
		add_action( 'admin_post_ytwp_oauth_start', array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_ytwp_oauth_callback', array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_ytwp_oauth_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	/**
	 * The redirect URI to register in Google Cloud Console. Shown on the
	 * settings screen so it can be copied verbatim — a mismatch here is the
	 * most common reason the consent screen refuses to load.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=ytwp_oauth_callback' );
	}

	/**
	 * True when a refresh token is stored for the channel (the main channel
	 * when none is named).
	 *
	 * @param string $channel_id Channel id.
	 * @return bool
	 */
	public static function is_connected( $channel_id = '' ) {
		$channel = YTWP_Settings::channel( $channel_id );
		if ( null === $channel ) {
			return '' !== (string) YTWP_Settings::get( 'google_refresh_token', '' );
		}
		return '' !== $channel['refresh_token'];
	}

	/**
	 * The channel a connect or disconnect link is for. Empty means the main
	 * channel, as it always did before there was more than one.
	 *
	 * @return string
	 */
	private static function requested_channel() {
		return isset( $_GET['channel'] ) ? sanitize_text_field( wp_unslash( $_GET['channel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Store a refresh token for one channel. The main channel keeps its
	 * original setting; the others live in their row of extra_channels.
	 *
	 * @param string $channel_id Channel id, empty for the main channel.
	 * @param string $token      Refresh token, empty to forget it.
	 */
	private static function save_token( $channel_id, $token ) {
		// channel() falls back to the main channel for an unknown id, which
		// here would hand the main channel somebody else's token.
		if ( ! self::is_known_channel( $channel_id ) ) {
			self::back_with( 'unknown-channel' );
		}

		$channel = '' !== $channel_id ? YTWP_Settings::channel( $channel_id ) : null;

		if ( null === $channel || $channel['main'] ) {
			YTWP_Settings::update( array( 'google_refresh_token' => $token ) );
		} else {
			$rows = array();
			foreach ( (array) YTWP_Settings::get( 'extra_channels', array() ) as $row ) {
				if ( isset( $row['channel_id'] ) && $row['channel_id'] === $channel_id ) {
					$row['refresh_token'] = $token;
				}
				$rows[] = $row;
			}
			YTWP_Settings::update( array( 'extra_channels' => $rows ) );
		}

		delete_transient( self::cache_key( $channel_id ) );
	}

	/**
	 * True for the main channel (empty id) or a channel saved in settings.
	 *
	 * @param string $channel_id Channel id.
	 * @return bool
	 */
	private static function is_known_channel( $channel_id ) {
		return '' === $channel_id || array_key_exists( $channel_id, YTWP_Settings::channels() );
	}

	/**
	 * Where a channel's short-lived access token is cached.
	 *
	 * @param string $channel_id Channel id.
	 * @return string
	 */
	private static function cache_key( $channel_id ) {
		$channel = YTWP_Settings::channel( $channel_id );
		if ( null === $channel || $channel['main'] ) {
			return self::ACCESS_CACHE;
		}
		return self::ACCESS_CACHE . '_' . md5( $channel['channel_id'] );
	}

	/**
	 * Send the admin to Google's consent screen.
	 */
	public static function handle_start() {
		self::guard( 'ytwp_oauth_start' );

		$client_id = (string) YTWP_Settings::get( 'google_client_id', '' );
		if ( '' === $client_id ) {
			self::back_with( 'missing-client' );
		}

		if ( ! self::is_known_channel( self::requested_channel() ) ) {
			self::back_with( 'unknown-channel' );
		}

		$state = wp_generate_password( 24, false );
		// The channel rides along with the state, so the callback knows whose
		// token it just received.
		update_option(
			self::STATE_OPTION,
			array(
				'state'   => $state,
				'channel' => self::requested_channel(),
			),
			false
		);

		$url = add_query_arg(
			array(
				'client_id'     => rawurlencode( $client_id ),
				'redirect_uri'  => rawurlencode( self::redirect_uri() ),
				'response_type' => 'code',
				'scope'         => rawurlencode( self::SCOPE ),
				// Without offline access and forced consent Google returns an
				// access token only, and the plugin would stop working within
				// the hour. select_account makes Google ask which account to
				// use instead of silently taking the first one signed in, which
				// is rarely the one that owns the channel.
				'access_type'   => 'offline',
				'prompt'        => 'select_account consent',
				'state'         => rawurlencode( $state ),
			),
			self::AUTH_URL
		);

		wp_redirect( $url );
		exit;
	}

	/**
	 * Exchange the authorization code for a refresh token.
	 */
	public static function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'yt-to-wp-transcription' ) );
		}

		$saved    = get_option( self::STATE_OPTION, '' );
		$expected = is_array( $saved ) ? (string) $saved['state'] : (string) $saved;
		$channel  = is_array( $saved ) ? (string) $saved['channel'] : '';
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		delete_option( self::STATE_OPTION );

		if ( '' === $expected || ! hash_equals( $expected, $state ) ) {
			self::back_with( 'bad-state' );
		}

		if ( isset( $_GET['error'] ) ) {
			self::back_with( 'denied' );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			self::back_with( 'no-code' );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $code,
					'client_id'     => (string) YTWP_Settings::get( 'google_client_id', '' ),
					'client_secret' => (string) YTWP_Settings::get( 'google_client_secret', '' ),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::back_with( 'token-request-failed' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['refresh_token'] ) ) {
			// Google only returns a refresh token on first consent; re-consent
			// is forced above, so an empty one here means something else broke.
			self::back_with( 'no-refresh-token' );
		}

		// Google asks which channel to act as when one account manages
		// several, and a token for the wrong one fails much later, on the
		// first caption download. Check it now, while the admin is here.
		$expected_id = '' !== $channel ? $channel : trim( (string) YTWP_Settings::get( 'channel_id', '' ) );
		if ( '' !== $expected_id && ! empty( $body['access_token'] ) ) {
			$picked = YTWP_YouTube::token_channel( $body['access_token'] );
			if ( ! is_wp_error( $picked ) && $picked['id'] !== $expected_id ) {
				self::back_with(
					'detail',
					'error',
					sprintf(
						/* translators: 1: channel the admin signed in as, 2: channel id being connected. */
						__( 'You signed in as the channel "%1$s", which is not %2$s. Connect again and pick that channel in Google\'s account chooser.', 'yt-to-wp-transcription' ),
						$picked['title'],
						$expected_id
					)
				);
			}
		}

		self::save_token( $channel, $body['refresh_token'] );
		delete_transient( 'ytwp_token_channel_' . md5( $expected_id ) );

		self::back_with( 'connected', 'updated' );
	}

	/**
	 * Forget the stored token.
	 */
	public static function handle_disconnect() {
		self::guard( 'ytwp_oauth_disconnect' );

		self::save_token( self::requested_channel(), '' );

		self::back_with( 'disconnected', 'updated' );
	}

	/**
	 * A usable access token for the channel, minted from its refresh token
	 * and cached for slightly less than its real lifetime. Each channel has
	 * its own: Google issues a token per channel, even when one account
	 * manages them all.
	 *
	 * @param string $channel_id Channel id, empty for the main channel.
	 * @return string|WP_Error
	 */
	public static function access_token( $channel_id = '' ) {
		$cache  = self::cache_key( $channel_id );
		$cached = get_transient( $cache );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$channel = YTWP_Settings::channel( $channel_id );
		$refresh = null !== $channel ? $channel['refresh_token'] : (string) YTWP_Settings::get( 'google_refresh_token', '' );
		if ( '' === $refresh ) {
			return new WP_Error(
				'ytwp_not_connected',
				null !== $channel && ! $channel['main']
					? sprintf(
						/* translators: %s: YouTube channel id. */
						__( 'Channel %s is not connected to YouTube yet. Connect it on the settings screen.', 'yt-to-wp-transcription' ),
						$channel['channel_id']
					)
					: __( 'The site is not connected to YouTube yet. Connect it on the settings screen.', 'yt-to-wp-transcription' )
			);
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'     => (string) YTWP_Settings::get( 'google_client_id', '' ),
					'client_secret' => (string) YTWP_Settings::get( 'google_client_secret', '' ),
					'refresh_token' => $refresh,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$detail = isset( $body['error_description'] ) ? $body['error_description'] : wp_remote_retrieve_body( $response );
			return new WP_Error(
				'ytwp_token_refresh_failed',
				sprintf(
					/* translators: %s: error text returned by Google. */
					__( 'Could not refresh the YouTube token: %s', 'yt-to-wp-transcription' ),
					wp_strip_all_tags( (string) $detail )
				)
			);
		}

		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 120 ) : 1800;
		set_transient( $cache, $body['access_token'], $ttl );

		return $body['access_token'];
	}

	/**
	 * Capability and nonce check shared by the admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'yt-to-wp-transcription' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Return to the settings screen carrying a message code.
	 *
	 * @param string $notice Message code.
	 * @param string $type   'error' or 'updated'.
	 * @param string $detail Message text, for the 'detail' code.
	 */
	private static function back_with( $notice, $type = 'error', $detail = '' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => YTWP_Admin::SETTINGS_SLUG,
					'ytwp_notice' => $notice,
					'ytwp_type'   => $type,
					'ytwp_detail' => '' !== $detail ? rawurlencode( $detail ) : false,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
