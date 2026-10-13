<?php
/**
 * Prints the request the plugin would send for one case, as JSON.
 *
 * The eval calls this instead of keeping its own copy of the prompt, so
 * there is one source of truth: a prompt change in the plugin shows up in
 * the next eval run without anyone remembering to mirror it.
 *
 *   php render_prompt.php cases/<video_id>.json
 *
 * @package YT_To_WP_Transcription
 */

define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

// The handful of WordPress functions the prompt builders touch.
function __( $s, $d = null ) { return $s; }
function _n( $a, $b, $n, $d = null ) { return 1 === $n ? $a : $b; }
function wp_parse_args( $a, $b ) { return array_merge( $b, (array) $a ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; }
function sanitize_textarea_field( $s ) { return $s; }
function wp_unslash( $s ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function add_action() {}
function register_setting() {}
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }

$GLOBALS['opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opt'][ $k ] = $v; return true; }

$root = dirname( __DIR__ );
require $root . '/includes/class-ytwp-settings.php';
require $root . '/includes/class-ytwp-youtube.php';
require $root . '/includes/class-ytwp-claude.php';

$case_file = $argv[1] ?? '';
if ( ! is_readable( $case_file ) ) {
	fwrite( STDERR, "usage: php render_prompt.php cases/<video_id>.json\n" );
	exit( 1 );
}

$case = json_decode( file_get_contents( $case_file ), true );

$GLOBALS['opt']['ytwp_settings'] = array(
	'caption_language' => getenv( 'YTWP_LANGUAGE' ) ?: 'es',
	'editorial_notes'  => getenv( 'YTWP_NOTES' ) ?: '',
);

$segments = array();
foreach ( $case['segments'] as $s ) {
	$segments[] = array(
		'start' => $s['start'],
		'text'  => $s['text'],
	);
}

$video = array(
	'video_id'         => $case['video_id'],
	'title'            => $case['title'],
	'description'      => $case['description'],
	'published_at'     => $case['published_at'],
	'duration_iso'     => $case['duration_iso'],
	'duration_seconds' => $case['duration_seconds'],
);

// Reach the private builders the same way the live call does.
$system = ( new ReflectionMethod( 'YTWP_Claude', 'system_prompt' ) )->invoke( null );
$user   = ( new ReflectionMethod( 'YTWP_Claude', 'user_prompt' ) )->invoke(
	null,
	$video,
	YTWP_YouTube::to_timestamped_text( $segments ),
	true
);
$tool   = ( new ReflectionMethod( 'YTWP_Claude', 'tool' ) )->invoke( null );

echo wp_json_encode(
	array(
		'video'  => $video,
		'system' => $system,
		'user'   => $user,
		'tool'   => $tool,
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
