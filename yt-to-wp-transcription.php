<?php
/**
 * Plugin Name:       YT to WP Transcription
 * Plugin URI:        https://github.com/rocaiguina/YT-to-WP-transcription
 * Description:       Watches a YouTube channel and turns each upload into a WordPress draft carrying an edited, readable transcript.
 * Version:           0.2.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Jagual Media
 * Author URI:        https://github.com/rocaiguina
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       yt-to-wp-transcription
 *
 * @package YT_To_WP_Transcription
 */

defined( 'ABSPATH' ) || exit;

define( 'YTWP_VERSION', '0.2.4' );
define( 'YTWP_FILE', __FILE__ );
define( 'YTWP_PATH', plugin_dir_path( __FILE__ ) );
define( 'YTWP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Cron hooks. Scanning the channel is cheap and runs hourly. The worker
 * handles a single video per tick, so no PHP request ever has to survive a
 * whole batch — which is what makes this safe on managed hosting where
 * requests are cut off after 30 to 60 seconds.
 */
define( 'YTWP_CRON_SCAN', 'ytwp_scan_channel' );
define( 'YTWP_CRON_WORK', 'ytwp_process_next' );

require_once YTWP_PATH . 'includes/class-ytwp-settings.php';
require_once YTWP_PATH . 'includes/class-ytwp-store.php';
require_once YTWP_PATH . 'includes/class-ytwp-oauth.php';
require_once YTWP_PATH . 'includes/class-ytwp-youtube.php';
require_once YTWP_PATH . 'includes/class-ytwp-claude.php';
require_once YTWP_PATH . 'includes/class-ytwp-publisher.php';
require_once YTWP_PATH . 'includes/class-ytwp-worker.php';
require_once YTWP_PATH . 'includes/class-ytwp-admin.php';

/**
 * Wire up the pieces that have to be live on every request.
 */
function ytwp_bootstrap() {
	load_plugin_textdomain( 'yt-to-wp-transcription' );

	YTWP_Store::maybe_upgrade();
	YTWP_Settings::init();
	YTWP_OAuth::init();
	YTWP_Worker::init();
	YTWP_Admin::init();
}
add_action( 'plugins_loaded', 'ytwp_bootstrap' );

/**
 * WordPress ships no interval shorter than hourly, and one video per hour
 * would never keep up with an active channel.
 *
 * @param array $schedules Registered intervals.
 * @return array
 */
function ytwp_cron_schedules( $schedules ) {
	$schedules['ytwp_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every five minutes (YT to WP Transcription)', 'yt-to-wp-transcription' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'ytwp_cron_schedules' );

/**
 * Create the queue table and schedule both jobs.
 */
function ytwp_activate() {
	YTWP_Store::install();

	if ( ! wp_next_scheduled( YTWP_CRON_SCAN ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', YTWP_CRON_SCAN );
	}
	if ( ! wp_next_scheduled( YTWP_CRON_WORK ) ) {
		wp_schedule_event( time() + ( 2 * MINUTE_IN_SECONDS ), 'ytwp_five_minutes', YTWP_CRON_WORK );
	}
}
register_activation_hook( __FILE__, 'ytwp_activate' );

/**
 * Leave no scheduled work behind. The queue table and the saved keys stay
 * put, so deactivating and reactivating does not reprocess the channel.
 */
function ytwp_deactivate() {
	wp_clear_scheduled_hook( YTWP_CRON_SCAN );
	wp_clear_scheduled_hook( YTWP_CRON_WORK );
}
register_deactivation_hook( __FILE__, 'ytwp_deactivate' );
