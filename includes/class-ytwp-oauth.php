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
	 * True when a refresh token is stored.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== (string) YTWP_Settings::get( 'google_refresh_token', '' );
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

		$state = wp_generate_password( 24, false );
		update_option( self::STATE_OPTION, $state, false );

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

		$expected = get_option( self::STATE_OPTION, '' );
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

		YTWP_Settings::update( array( 'google_refresh_token' => $body['refresh_token'] ) );
		delete_transient( self::ACCESS_CACHE );

		self::back_with( 'connected', 'updated' );
	}

	/**
	 * Forget the stored token.
	 */
	public static function handle_disconnect() {
		self::guard( 'ytwp_oauth_disconnect' );

		YTWP_Settings::update( array( 'google_refresh_token' => '' ) );
		delete_transient( self::ACCESS_CACHE );

		self::back_with( 'disconnected', 'updated' );
	}

	/**
	 * A usable access token, minted from the refresh token and cached for
	 * slightly less than its real lifetime.
	 *
	 * @return string|WP_Error
	 */
	public static function access_token() {
		$cached = get_transient( self::ACCESS_CACHE );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$refresh = (string) YTWP_Settings::get( 'google_refresh_token', '' );
		if ( '' === $refresh ) {
			return new WP_Error(
				'ytwp_not_connected',
				__( 'The site is not connected to YouTube yet. Connect it on the settings screen.', 'yt-to-wp-transcription' )
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
		set_transient( self::ACCESS_CACHE, $body['access_token'], $ttl );

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
	 */
	private static function back_with( $notice, $type = 'error' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => YTWP_Admin::SETTINGS_SLUG,
					'ytwp_notice' => $notice,
					'ytwp_type'   => $type,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
