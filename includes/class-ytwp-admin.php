<?php
/**
 * Settings screen and queue screen.
 *
 * @package YT_To_WP_Transcription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything an administrator sees.
 */
class YTWP_Admin {

	const SETTINGS_SLUG = 'ytwp-settings';
	const QUEUE_SLUG    = 'ytwp-queue';

	/**
	 * Register menus and the manual-action handlers.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ytwp_run_scan', array( __CLASS__, 'handle_scan' ) );
		add_action( 'admin_post_ytwp_run_worker', array( __CLASS__, 'handle_worker' ) );
		add_action( 'admin_post_ytwp_requeue', array( __CLASS__, 'handle_requeue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * Settings under Settings, the queue under Tools.
	 */
	public static function menu() {
		add_options_page(
			__( 'YT to WP Transcription', 'yt-to-wp-transcription' ),
			__( 'YT Transcription', 'yt-to-wp-transcription' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( __CLASS__, 'render_settings' )
		);

		add_management_page(
			__( 'Video queue', 'yt-to-wp-transcription' ),
			__( 'Video queue', 'yt-to-wp-transcription' ),
			'manage_options',
			self::QUEUE_SLUG,
			array( __CLASS__, 'render_queue' )
		);
	}

	/**
	 * Messages carried back from a redirect.
	 */
	public static function notices() {
		if ( ! isset( $_GET['ytwp_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code = sanitize_key( wp_unslash( $_GET['ytwp_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['ytwp_type'] ) && 'updated' === $_GET['ytwp_type'] ? 'success' : 'error'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'connected'            => __( 'Connected to YouTube.', 'yt-to-wp-transcription' ),
			'disconnected'         => __( 'Disconnected from YouTube.', 'yt-to-wp-transcription' ),
			'missing-client'       => __( 'Save the Google client id and secret before connecting.', 'yt-to-wp-transcription' ),
			'bad-state'            => __( 'The sign-in could not be verified. Please try again.', 'yt-to-wp-transcription' ),
			'denied'               => __( 'Google sign-in was cancelled.', 'yt-to-wp-transcription' ),
			'no-code'              => __( 'Google did not return an authorization code.', 'yt-to-wp-transcription' ),
			'token-request-failed' => __( 'The site could not reach Google to exchange the code.', 'yt-to-wp-transcription' ),
			'no-refresh-token'     => __( 'Google returned no refresh token. Remove the app under your Google account permissions and connect again.', 'yt-to-wp-transcription' ),
			'requeued'             => __( 'Video put back in the queue.', 'yt-to-wp-transcription' ),
		);

		$message = isset( $messages[ $code ] ) ? $messages[ $code ] : '';
		if ( '' === $message ) {
			// Scan and worker handlers pass their own text through.
			$message = isset( $_GET['ytwp_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['ytwp_detail'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * The settings form.
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings  = YTWP_Settings::all();
		$connected = YTWP_OAuth::is_connected();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'YT to WP Transcription', 'yt-to-wp-transcription' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Watches a YouTube channel and turns each upload into a draft post carrying an edited transcript. Nothing is ever published automatically.', 'yt-to-wp-transcription' ); ?>
			</p>

			<h2 class="title"><?php esc_html_e( 'Setup status', 'yt-to-wp-transcription' ); ?></h2>
			<table class="widefat striped" style="max-width:48em">
				<tbody>
				<?php foreach ( YTWP_Settings::readiness() as $item ) : ?>
					<tr>
						<td style="width:14em"><strong><?php echo esc_html( $item['label'] ); ?></strong></td>
						<td style="width:7em">
							<?php if ( $item['ready'] ) : ?>
								<span style="color:#008a20">&#10003; <?php esc_html_e( 'Ready', 'yt-to-wp-transcription' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e">&#10007; <?php esc_html_e( 'Missing', 'yt-to-wp-transcription' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo $item['ready'] ? '' : esc_html( $item['hint'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="options.php">
				<?php settings_fields( YTWP_Settings::GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'Channel', 'yt-to-wp-transcription' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ytwp_enabled"><?php esc_html_e( 'Run automatically', 'yt-to-wp-transcription' ); ?></label></th>
						<td>
							<input type="checkbox" id="ytwp_enabled" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[enabled]" value="1" <?php checked( $settings['enabled'], 1 ); ?> />
							<span class="description"><?php esc_html_e( 'Check the channel hourly and process one video every five minutes.', 'yt-to-wp-transcription' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ytwp_channel_id"><?php esc_html_e( 'Channel id', 'yt-to-wp-transcription' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="ytwp_channel_id" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[channel_id]" value="<?php echo esc_attr( $settings['channel_id'] ); ?>" placeholder="UCxxxxxxxxxxxxxxxxxxxxxx" />
							<p class="description"><?php esc_html_e( 'Starts with UC. Find it in YouTube Studio under Settings, Channel, Advanced settings.', 'yt-to-wp-transcription' ); ?></p>
							<?php self::render_channel_check( $settings ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ytwp_caption_language"><?php esc_html_e( 'Caption language', 'yt-to-wp-transcription' ); ?></label></th>
						<td>
							<input type="text" class="small-text" id="ytwp_caption_language" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[caption_language]" value="<?php echo esc_attr( $settings['caption_language'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Two-letter code, such as en or es. A hand-uploaded track in this language wins over the automatic one.', 'yt-to-wp-transcription' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'API keys', 'yt-to-wp-transcription' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Keys are stored in the WordPress options table. Leave a box blank to keep the key already saved.', 'yt-to-wp-transcription' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ytwp_anthropic_api_key"><?php esc_html_e( 'Anthropic API key', 'yt-to-wp-transcription' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="ytwp_anthropic_api_key" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[anthropic_api_key]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( self::secret_placeholder( $settings['anthropic_api_key'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'From console.anthropic.com. This is what edits the transcript.', 'yt-to-wp-transcription' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ytwp_anthropic_model"><?php esc_html_e( 'Model', 'yt-to-wp-transcription' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="ytwp_anthropic_model" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[anthropic_model]" value="<?php echo esc_attr( $settings['anthropic_model'] ); ?>" />
							<p class="description"><?php esc_html_e( 'claude-haiku-4-5 is the cheapest and handles this well. claude-sonnet-5 costs about twice as much.', 'yt-to-wp-transcription' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ytwp_anthropic_workspace_id"><?php esc_html_e( 'Anthropic workspace id', 'yt-to-wp-transcription' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="ytwp_anthropic_workspace_id" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[anthropic_workspace_id]" value="<?php echo esc_attr( $settings['anthropic_workspace_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Only needed when your key belongs to the whole organization rather than one workspace. If the API answers that the key is not scoped to a workspace, paste the workspace id here, or create a key inside a workspace instead.', 'yt-to-wp-transcription' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ytwp_youtube_api_key"><?php esc_html_e( 'YouTube API key', 'yt-to-wp-transcription' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="ytwp_youtube_api_key" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[youtube_api_key]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( self::secret_placeholder( $settings['youtube_api_key'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Used to list the channel uploads.', 'yt-to-wp-transcription' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'YouTube account', 'yt-to-wp-transcription' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Caption tracks can only be downloaded by the account that owns the channel, so an API key is not enough. Create an OAuth client of type Web application in Google Cloud Console and register the redirect URL below.', 'yt-to-wp-transcription' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Redirect URL', 'yt-to-wp-transcription' ); ?></th>
						<td>
							<input type="text" class="large-text code" readonly value="<?php echo esc_attr( YTWP_OAuth::redirect_uri() ); ?>" onfocus="this.select()" />
							<p class="description"><?php esc_html_e( 'Paste this verbatim into the Authorized redirect URIs box in Google Cloud Console.', 'yt-to-wp-transcription' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ytwp_google_client_id"><?php esc_html_e( 'Client id', 'yt-to-wp-transcription' ); ?></label></th>
						<td><input type="text" class="regular-text" id="ytwp_google_client_id" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[google_client_id]" value="<?php echo esc_attr( $settings['google_client_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ytwp_google_client_secret"><?php esc_html_e( 'Client secret', 'yt-to-wp-transcription' ); ?></label></th>
						<td><input type="password" class="regular-text" id="ytwp_google_client_secret" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[google_client_secret]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( self::secret_placeholder( $settings['google_client_secret'] ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connection', 'yt-to-wp-transcription' ); ?></th>
						<td>
							<?php if ( $connected ) : ?>
								<p><strong><?php esc_html_e( 'Connected.', 'yt-to-wp-transcription' ); ?></strong></p>
							<?php else : ?>
								<p><?php esc_html_e( 'Not connected yet. Save the client id and secret first, then connect.', 'yt-to-wp-transcription' ); ?></p>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Connecting opens Google and returns here. Sign in with the account that owns the channel.', 'yt-to-wp-transcription' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'What becomes a post', 'yt-to-wp-transcription' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ytwp_earliest_video_date"><?php esc_html_e( 'Ignore videos published before', 'yt-to-wp-transcription' ); ?></label></th>
						<td>
							<input type="date" id="ytwp_earliest_video_date" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[earliest_video_date]" value="<?php echo esc_attr( $settings['earliest_video_date'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave empty to work through the whole channel. On a channel with years of uploads that means thousands of videos and a transcription bill to match, so a date is usually what you want.', 'yt-to-wp-transcription' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ytwp_min_transcript_words"><?php esc_html_e( 'Minimum transcript words', 'yt-to-wp-transcription' ); ?></label></th>
						<td>
							<input type="number" class="small-text" min="1" id="ytwp_min_transcript_words" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[min_transcript_words]" value="<?php echo esc_attr( $settings['min_transcript_words'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Below this a video is treated as a clip rather than an article. Roughly 400 words is three minutes of speech.', 'yt-to-wp-transcription' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Video length to transcribe', 'yt-to-wp-transcription' ); ?></th>
						<td>
							<label for="ytwp_min_duration_minutes"><?php esc_html_e( 'From', 'yt-to-wp-transcription' ); ?></label>
							<input type="number" class="small-text" min="0" id="ytwp_min_duration_minutes" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[min_duration_minutes]" value="<?php echo esc_attr( $settings['min_duration_minutes'] ); ?>" />
							<label for="ytwp_max_duration_minutes"><?php esc_html_e( 'up to', 'yt-to-wp-transcription' ); ?></label>
							<input type="number" class="small-text" min="0" id="ytwp_max_duration_minutes" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[max_duration_minutes]" value="<?php echo esc_attr( $settings['max_duration_minutes'] ); ?>" />
							<?php esc_html_e( 'minutes', 'yt-to-wp-transcription' ); ?>
							<p class="description"><?php esc_html_e( 'Only videos inside this range are transcribed. Zero on either side removes that end of the limit. A maximum is worth setting: a multi-hour recording is slow and expensive to transcribe, and is the most likely to time out.', 'yt-to-wp-transcription' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Videos outside the range', 'yt-to-wp-transcription' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[publish_short_clips]" value="1" <?php checked( $settings['publish_short_clips'], 1 ); ?> />
								<?php esc_html_e( 'Still give them a draft carrying the video and its description', 'yt-to-wp-transcription' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Unchecked, a video outside the range gets no post at all. This also covers videos with no caption track.', 'yt-to-wp-transcription' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ytwp_post_category"><?php esc_html_e( 'Category', 'yt-to-wp-transcription' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_categories(
								array(
									'show_option_none' => __( 'No category', 'yt-to-wp-transcription' ),
									'option_none_value' => 0,
									'hide_empty'       => false,
									'selected'         => (int) $settings['post_category'],
									'name'             => YTWP_Settings::OPTION . '[post_category]',
									'id'               => 'ytwp_post_category',
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ytwp_post_author"><?php esc_html_e( 'Author', 'yt-to-wp-transcription' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'show_option_none'  => __( 'Default', 'yt-to-wp-transcription' ),
									'option_none_value' => 0,
									'selected'          => (int) $settings['post_author'],
									'name'              => YTWP_Settings::OPTION . '[post_author]',
									'id'                => 'ytwp_post_author',
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ytwp_editorial_notes"><?php esc_html_e( 'House notes', 'yt-to-wp-transcription' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="4" id="ytwp_editorial_notes" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[editorial_notes]"><?php echo esc_textarea( $settings['editorial_notes'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Added to the editing instructions. Useful for recurring names the transcriber mishears, or a house style rule.', 'yt-to-wp-transcription' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Wording inside published posts', 'yt-to-wp-transcription' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'These headings appear in the post itself, so they belong in the language your audience reads, which has nothing to do with the language of this admin screen. They start out matching the caption language above.', 'yt-to-wp-transcription' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<?php
					$label_fields = array(
						'label_key_points' => __( 'Key points heading', 'yt-to-wp-transcription' ),
						'label_transcript' => __( 'Transcript heading', 'yt-to-wp-transcription' ),
						'label_sources'    => __( 'Sources heading', 'yt-to-wp-transcription' ),
						'label_watch'      => __( 'Link to the video', 'yt-to-wp-transcription' ),
					);
					foreach ( $label_fields as $key => $label ) :
						?>
						<tr>
							<th scope="row"><label for="ytwp_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input type="text" class="regular-text" id="ytwp_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $settings[ $key ] ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2 class="title"><?php esc_html_e( 'Advanced Custom Fields (optional)', 'yt-to-wp-transcription' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'The article always goes into the post content. If your theme renders from ACF fields instead, name them here and the plugin will fill those too. Leave blank to skip.', 'yt-to-wp-transcription' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<?php
					$acf_fields = array(
						'acf_body_field'          => __( 'Body field', 'yt-to-wp-transcription' ),
						'acf_summary_field'       => __( 'Summary field', 'yt-to-wp-transcription' ),
						'acf_video_field'         => __( 'Video id field', 'yt-to-wp-transcription' ),
						'acf_sources_label_field' => __( 'Sources heading field', 'yt-to-wp-transcription' ),
					);
					foreach ( $acf_fields as $key => $label ) :
						?>
						<tr>
							<th scope="row"><label for="ytwp_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input type="text" class="regular-text code" id="ytwp_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $settings[ $key ] ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="ytwp_acf_source_link_fields"><?php esc_html_e( 'Source link fields', 'yt-to-wp-transcription' ); ?></label></th>
						<td>
							<input type="text" class="large-text code" id="ytwp_acf_source_link_fields" name="<?php echo esc_attr( YTWP_Settings::OPTION ); ?>[acf_source_link_fields]" value="<?php echo esc_attr( $settings['acf_source_link_fields'] ); ?>" placeholder="article_link_1, article_link_2, article_link_3" />
							<p class="description"><?php esc_html_e( 'Comma separated ACF link fields, filled in order. Sources beyond the last one stay in the body.', 'yt-to-wp-transcription' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2 class="title"><?php esc_html_e( 'Connect', 'yt-to-wp-transcription' ); ?></h2>
			<p>
				<?php if ( $connected ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ytwp_oauth_start' ), 'ytwp_oauth_start' ) ); ?>"><?php esc_html_e( 'Reconnect to YouTube', 'yt-to-wp-transcription' ); ?></a>
					<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ytwp_oauth_disconnect' ), 'ytwp_oauth_disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect', 'yt-to-wp-transcription' ); ?></a>
				<?php else : ?>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ytwp_oauth_start' ), 'ytwp_oauth_start' ) ); ?>"><?php esc_html_e( 'Connect to YouTube', 'yt-to-wp-transcription' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Show which channel the saved id actually resolves to. Two channels can
	 * share a name, so the upload count is the part that matters: it is what
	 * separates a main channel from a near-empty namesake.
	 *
	 * @param array $settings Current settings.
	 */
	private static function render_channel_check( array $settings ) {
		$channel_id = trim( (string) $settings['channel_id'] );
		if ( '' === $channel_id || '' === trim( (string) $settings['youtube_api_key'] ) ) {
			return;
		}

		$cache_key = 'ytwp_channel_' . md5( $channel_id );
		$info      = get_transient( $cache_key );

		if ( false === $info ) {
			$info = YTWP_YouTube::describe_channel( $channel_id );
			set_transient( $cache_key, $info, 10 * MINUTE_IN_SECONDS );
		}

		if ( is_wp_error( $info ) ) {
			printf(
				'<p class="description" style="color:#b32d2e"><strong>%s</strong></p>',
				esc_html( $info->get_error_message() )
			);
			return;
		}

		printf(
			'<p class="description" style="color:#008a20"><strong>%s</strong> %s</p>',
			esc_html( $info['title'] ),
			esc_html(
				sprintf(
					/* translators: 1: channel handle, 2: number of uploads. */
					_n( '%1$s · %2$s video on the channel', '%1$s · %2$s videos on the channel', $info['videos'], 'yt-to-wp-transcription' ),
					$info['custom_url'],
					number_format_i18n( $info['videos'] )
				)
			)
		);
	}

	/**
	 * A hint that a secret is already stored, without revealing it.
	 *
	 * @param string $value Stored secret.
	 * @return string
	 */
	private static function secret_placeholder( $value ) {
		if ( '' === (string) $value ) {
			return __( 'Not set', 'yt-to-wp-transcription' );
		}
		return __( 'Saved — leave blank to keep', 'yt-to-wp-transcription' );
	}

	/**
	 * The queue screen.
	 */
	public static function render_queue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$counts   = YTWP_Store::counts();
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows     = YTWP_Store::listing(
			array(
				'status'   => $status,
				'page'     => $paged,
				'per_page' => 25,
			)
		);
		$last_run = YTWP_Worker::last_run();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Video queue', 'yt-to-wp-transcription' ); ?></h1>

			<?php
			$missing = YTWP_Settings::missing();
			if ( ! empty( $missing ) ) :
				?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: comma separated list of missing credentials. */
							esc_html__( 'Still missing: %s.', 'yt-to-wp-transcription' ),
							esc_html( implode( ', ', $missing ) )
						);
						?>
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'Finish the setup', 'yt-to-wp-transcription' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ytwp_run_scan' ), 'ytwp_run_scan' ) ); ?>"><?php esc_html_e( 'Check the channel now', 'yt-to-wp-transcription' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ytwp_run_worker' ), 'ytwp_run_worker' ) ); ?>"><?php esc_html_e( 'Process the next video', 'yt-to-wp-transcription' ); ?></a>
			</p>

			<p class="description">
				<?php
				if ( $last_run ) {
					printf(
						/* translators: %s: human readable time difference. */
						esc_html__( 'The worker last picked up a video %s ago.', 'yt-to-wp-transcription' ),
						esc_html( human_time_diff( $last_run, time() ) )
					);
				} else {
					esc_html_e( 'The worker has not run yet.', 'yt-to-wp-transcription' );
				}
				?>
			</p>

			<ul class="subsubsub">
				<?php
				$filters = array(
					''                         => __( 'All', 'yt-to-wp-transcription' ),
					YTWP_Store::STATUS_PENDING => __( 'Waiting', 'yt-to-wp-transcription' ),
					YTWP_Store::STATUS_DONE    => __( 'Drafted', 'yt-to-wp-transcription' ),
					YTWP_Store::STATUS_SKIPPED => __( 'Passed over', 'yt-to-wp-transcription' ),
					YTWP_Store::STATUS_ERROR   => __( 'Failed', 'yt-to-wp-transcription' ),
				);
				$links   = array();
				foreach ( $filters as $key => $label ) {
					$url     = add_query_arg(
						array_filter(
							array(
								'page'   => self::QUEUE_SLUG,
								'status' => $key,
							)
						),
						admin_url( 'tools.php' )
					);
					$count   = '' === $key ? array_sum( $counts ) : ( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 );
					$current = $status === $key ? ' class="current"' : '';
					$links[] = sprintf(
						'<li><a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a></li>',
						esc_url( $url ),
						$current,
						esc_html( $label ),
						(int) $count
					);
				}
				echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped
				?>
			</ul>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Published', 'yt-to-wp-transcription' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Video', 'yt-to-wp-transcription' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Length', 'yt-to-wp-transcription' ); ?></th>
						<th scope="col"><?php esc_html_e( 'State', 'yt-to-wp-transcription' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Draft', 'yt-to-wp-transcription' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Note', 'yt-to-wp-transcription' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Nothing here yet. Use "Check the channel now" to look for uploads.', 'yt-to-wp-transcription' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( gmdate( 'Y-m-d', strtotime( $row->published_at ) ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( YTWP_Publisher::video_url( $row->video_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row->title ); ?></a>
							</td>
							<td><?php echo esc_html( self::format_length( (int) $row->duration_seconds ) ); ?></td>
							<td>
								<?php echo esc_html( self::status_label( $row->status, $row->kind ) ); ?>
								<?php if ( YTWP_Store::STATUS_WORKING !== $row->status ) : ?>
									<br />
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ytwp_requeue&video=' . rawurlencode( $row->video_id ) ), 'ytwp_requeue_' . $row->video_id ) ); ?>"><?php esc_html_e( 'Run again', 'yt-to-wp-transcription' ); ?></a>
									<?php if ( $row->post_id ) : ?>
										<br /><span class="description"><?php esc_html_e( 'Delete the draft first, or it will be skipped.', 'yt-to-wp-transcription' ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $row->post_id ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( (int) $row->post_id ) ); ?>"><?php esc_html_e( 'Edit draft', 'yt-to-wp-transcription' ); ?></a>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) $row->note ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Seconds as a readable length.
	 *
	 * @param int $seconds Duration.
	 * @return string
	 */
	private static function format_length( $seconds ) {
		if ( $seconds <= 0 ) {
			return '—';
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			return sprintf( '%d:%02d', floor( $seconds / 60 ), $seconds % 60 );
		}
		return sprintf( '%d:%02d:%02d', floor( $seconds / HOUR_IN_SECONDS ), floor( ( $seconds % HOUR_IN_SECONDS ) / 60 ), $seconds % 60 );
	}

	/**
	 * A plain-language state.
	 *
	 * @param string $status Row status.
	 * @param string $kind   Row kind.
	 * @return string
	 */
	private static function status_label( $status, $kind ) {
		switch ( $status ) {
			case YTWP_Store::STATUS_PENDING:
				return __( 'Waiting', 'yt-to-wp-transcription' );
			case YTWP_Store::STATUS_WORKING:
				return __( 'Working', 'yt-to-wp-transcription' );
			case YTWP_Store::STATUS_DONE:
				return 'clip' === $kind
					? __( 'Drafted (clip)', 'yt-to-wp-transcription' )
					: __( 'Drafted (transcript)', 'yt-to-wp-transcription' );
			case YTWP_Store::STATUS_SKIPPED:
				return __( 'Passed over', 'yt-to-wp-transcription' );
			case YTWP_Store::STATUS_ERROR:
				return __( 'Failed', 'yt-to-wp-transcription' );
			default:
				return $status;
		}
	}

	/**
	 * Run a channel check from the button.
	 */
	public static function handle_scan() {
		self::guard( 'ytwp_run_scan' );

		$result = YTWP_Worker::scan();

		if ( is_wp_error( $result ) ) {
			self::back( $result->get_error_message(), 'error' );
		}

		self::back(
			sprintf(
				/* translators: 1: videos inspected, 2: new videos queued. */
				__( 'Checked %1$d videos, queued %2$d new ones.', 'yt-to-wp-transcription' ),
				$result['seen'],
				$result['added']
			),
			'updated'
		);
	}

	/**
	 * Process one video from the button.
	 */
	public static function handle_worker() {
		self::guard( 'ytwp_run_worker' );

		$result = YTWP_Worker::process_next();

		if ( is_wp_error( $result ) ) {
			self::back( $result->get_error_message(), 'error' );
		}

		$messages = array(
			'idle'    => __( 'Nothing is waiting in the queue.', 'yt-to-wp-transcription' ),
			'done'    => __( 'Drafted an article from the transcript.', 'yt-to-wp-transcription' ),
			'clip'    => __( 'Drafted a clip post.', 'yt-to-wp-transcription' ),
			'skipped' => __( 'That video was passed over.', 'yt-to-wp-transcription' ),
		);
		$status   = isset( $result['status'] ) ? $result['status'] : 'idle';

		self::back(
			isset( $messages[ $status ] ) ? $messages[ $status ] : $status,
			'updated'
		);
	}

	/**
	 * Put a failed video back in line.
	 */
	public static function handle_requeue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'yt-to-wp-transcription' ) );
		}

		$video_id = isset( $_GET['video'] ) ? sanitize_text_field( wp_unslash( $_GET['video'] ) ) : '';
		check_admin_referer( 'ytwp_requeue_' . $video_id );

		if ( '' !== $video_id ) {
			YTWP_Store::requeue( $video_id );
		}

		self::back( __( 'Video put back in the queue.', 'yt-to-wp-transcription' ), 'updated' );
	}

	/**
	 * Capability and nonce check.
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
	 * Back to the queue screen with a message.
	 *
	 * @param string $detail Message text.
	 * @param string $type   'updated' or 'error'.
	 */
	private static function back( $detail, $type ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::QUEUE_SLUG,
					'ytwp_notice' => 'detail',
					'ytwp_detail' => rawurlencode( $detail ),
					'ytwp_type'   => $type,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}
}
