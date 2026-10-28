<?php
/**
 * Writes the finished article into WordPress.
 *
 * @package YT_To_WP_Transcription
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates the draft. Everything lands in post_content by default, so the
 * plugin works on a stock install; a site whose theme reads Advanced Custom
 * Fields can map the pieces onto its own fields instead.
 */
class YTWP_Publisher {

	/**
	 * Meta key holding the source video id. Also the guard against making a
	 * second post for a video that already has one.
	 */
	const VIDEO_META_KEY = '_ytwp_video_id';

	/**
	 * Meta key holding the editor checklist the model produced.
	 */
	const REVIEW_META_KEY = '_ytwp_needs_review';

	/**
	 * Create a draft from a full article.
	 *
	 * @param array $video Video data.
	 * @param array $draft Editorial material from YTWP_Claude.
	 * @return int|WP_Error Post id.
	 */
	public static function create_article( array $video, array $draft ) {
		$title = '' !== trim( $draft['title'] ) ? $draft['title'] : $video['title'];

		$post_id = self::insert(
			$video,
			$title,
			self::embed_block( $video ) . self::render_article( $video, $draft )
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::apply_acf( $post_id, $video, $draft );

		if ( ! empty( $draft['needs_review'] ) ) {
			update_post_meta( $post_id, self::REVIEW_META_KEY, array_map( 'sanitize_text_field', $draft['needs_review'] ) );
		}

		return $post_id;
	}

	/**
	 * Create a draft for a video with no usable transcript: a Short, a clip
	 * of a couple of minutes, or one with no caption track. It carries the
	 * video and its description, which is better than the video having no
	 * page at all, and stays short enough that a site with a thin-content
	 * rule will treat it accordingly.
	 *
	 * @param array $video Video data.
	 * @return int|WP_Error Post id.
	 */
	public static function create_clip( array $video ) {
		$body = trim( $video['description'] );
		$body = '' !== $body ? wpautop( esc_html( $body ) ) : '';

		$post_id = self::insert( $video, $video['title'], self::embed_block( $video ) . $body . self::watch_link( $video ) );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::apply_acf( $post_id, $video, null );

		return $post_id;
	}

	/**
	 * The article as HTML.
	 *
	 * @param array $video Video data.
	 * @param array $draft Editorial material.
	 * @return string
	 */
	public static function render_article( array $video, array $draft ) {
		$out = '';

		foreach ( $draft['lede'] as $paragraph ) {
			$out .= '<p>' . esc_html( $paragraph ) . "</p>\n";
		}

		if ( ! empty( $draft['key_points'] ) ) {
			$out .= "\n<h2>" . esc_html( self::label( 'label_key_points' ) ) . "</h2>\n<ul>\n";
			foreach ( $draft['key_points'] as $point ) {
				$out .= '<li>' . esc_html( $point ) . "</li>\n";
			}
			$out .= "</ul>\n";
		}

		// The body runs straight on from the opening. There is no heading
		// announcing a transcript, because this is not one: it is the
		// article covering the same stories.
		foreach ( $draft['sections'] as $section ) {
			$heading = isset( $section['heading'] ) ? $section['heading'] : '';

			if ( '' !== trim( $heading ) ) {
				$out .= "\n<h2>" . esc_html( $heading ) . "</h2>\n";
			}

			foreach ( ( isset( $section['paragraphs'] ) ? $section['paragraphs'] : array() ) as $paragraph ) {
				$out .= '<p>' . esc_html( $paragraph ) . "</p>\n";
			}
		}

		$out .= self::watch_link( $video );

		$sources = self::usable_sources( $draft );
		if ( ! empty( $sources ) ) {
			$out .= "\n<h2>" . esc_html( self::label( 'label_sources' ) ) . "</h2>\n<ul>\n";
			foreach ( $sources as $source ) {
				$label = trim( $source['title'] );
				if ( '' !== trim( $source['outlet'] ) ) {
					$label .= ' (' . trim( $source['outlet'] ) . ')';
				}

				$out .= '<li>';
				if ( '' !== trim( $source['url'] ) ) {
					$out .= '<a href="' . esc_url( $source['url'] ) . '" rel="noopener noreferrer" target="_blank">' . esc_html( $label ) . '</a>';
				} else {
					$out .= esc_html( $label );
				}
				$out .= "</li>\n";
			}
			$out .= "</ul>\n";
		}

		return $out;
	}

	/**
	 * Sources that carry at least a label.
	 *
	 * @param array $draft Editorial material.
	 * @return array[]
	 */
	private static function usable_sources( array $draft ) {
		$sources = array();
		foreach ( $draft['sources'] as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			$source = wp_parse_args(
				$source,
				array(
					'title'  => '',
					'outlet' => '',
					'url'    => '',
				)
			);
			if ( '' === trim( $source['title'] ) && '' === trim( $source['outlet'] ) ) {
				continue;
			}
			$sources[] = $source;
		}
		return $sources;
	}

	/**
	 * A heading as it should read in the published post. These are settings
	 * rather than translated interface strings: the post is written in the
	 * language of the video, which has nothing to do with the language the
	 * WordPress admin happens to be in.
	 *
	 * @param string $key Setting name.
	 * @return string
	 */
	private static function label( $key ) {
		$value = trim( (string) YTWP_Settings::get( $key, '' ) );
		if ( '' !== $value ) {
			return $value;
		}
		$pack = YTWP_Settings::default_labels( YTWP_Settings::get( 'caption_language', 'en' ) );
		return isset( $pack[ $key ] ) ? $pack[ $key ] : '';
	}

	/**
	 * A line pointing at the original video.
	 *
	 * @param array $video Video data.
	 * @return string
	 */
	private static function watch_link( array $video ) {
		$url = self::video_url( $video['video_id'] );

		return "\n<p>" . esc_html( self::label( 'label_watch' ) ) . ' '
			. '<a href="' . esc_url( $url ) . '" rel="noopener noreferrer" target="_blank">' . esc_html( $url ) . '</a>'
			. "</p>\n";
	}

	/**
	 * The video as a WordPress embed block, so the post shows the player
	 * wherever WordPress renders the content (the site, its feed). It goes
	 * into the post content only: the ACF body is plain article text, read
	 * by the website and the app, which show the video from the video id.
	 *
	 * @param array $video Video data.
	 * @return string
	 */
	private static function embed_block( array $video ) {
		$url = self::video_url( $video['video_id'] );

		return '<!-- wp:embed {"url":"' . esc_url( $url ) . '","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->' . "\n"
			. '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . "\n"
			. esc_url( $url ) . "\n"
			. "</div></figure>\n"
			. "<!-- /wp:embed -->\n\n";
	}

	/**
	 * Canonical watch URL.
	 *
	 * @param string $video_id Video id.
	 * @return string
	 */
	public static function video_url( $video_id ) {
		return 'https://www.youtube.com/watch?v=' . $video_id;
	}

	/**
	 * Insert the draft itself.
	 *
	 * @param array  $video   Video data.
	 * @param string $title   Post title.
	 * @param string $content Post content.
	 * @return int|WP_Error
	 */
	private static function insert( array $video, $title, $content ) {
		$postarr = array(
			'post_type'    => 'post',
			// Always a draft. Nothing this plugin produces goes live without
			// someone reading it first.
			'post_status'  => 'draft',
			'post_title'   => wp_strip_all_tags( $title ),
			'post_content' => $content,
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $video['published_at'] ) ),
			'meta_input'   => array( self::VIDEO_META_KEY => $video['video_id'] ),
		);

		// Each channel files its posts under its own category; one left on
		// "same as the main channel" falls back to the main category.
		$channel  = YTWP_Settings::channel( isset( $video['channel_id'] ) ? $video['channel_id'] : '' );
		$category = null !== $channel ? (int) $channel['post_category'] : 0;
		if ( $category < 1 ) {
			$category = (int) YTWP_Settings::get( 'post_category', 0 );
		}
		if ( $category > 0 ) {
			$postarr['post_category'] = array( $category );
		}

		$author = (int) YTWP_Settings::get( 'post_author', 0 );
		if ( $author > 0 ) {
			$postarr['post_author'] = $author;
		}

		$post_id = wp_insert_post( $postarr, true );

		return $post_id;
	}

	/**
	 * Mirror the article into Advanced Custom Fields, for themes that render
	 * from fields rather than from post_content. Every mapping is optional;
	 * an unset field name is simply skipped.
	 *
	 * @param int        $post_id Post id.
	 * @param array      $video   Video data.
	 * @param array|null $draft   Editorial material, or null for a clip.
	 */
	private static function apply_acf( $post_id, array $video, $draft ) {
		if ( ! function_exists( 'update_field' ) ) {
			return;
		}

		$video_field = (string) YTWP_Settings::get( 'acf_video_field', '' );
		if ( '' !== $video_field ) {
			update_field( $video_field, $video['video_id'], $post_id );
		}

		$body_field = (string) YTWP_Settings::get( 'acf_body_field', '' );
		if ( '' !== $body_field ) {
			update_field(
				$body_field,
				null === $draft ? trim( $video['description'] ) : self::render_article( $video, $draft ),
				$post_id
			);
		}

		if ( null === $draft ) {
			return;
		}

		$summary_field = (string) YTWP_Settings::get( 'acf_summary_field', '' );
		if ( '' !== $summary_field && ! empty( $draft['key_points'] ) ) {
			update_field( $summary_field, implode( "\n", $draft['key_points'] ), $post_id );
		}

		$sources = self::usable_sources( $draft );

		$label_field = (string) YTWP_Settings::get( 'acf_sources_label_field', '' );
		if ( '' !== $label_field && ! empty( $sources ) ) {
			update_field( $label_field, self::label( 'label_sources' ), $post_id );
		}

		// ACF link fields expect {title, url}. Sites usually have a fixed
		// handful of them, so only the first few sources fit; the rest stay
		// in the body, where render_article already listed them.
		$link_fields = YTWP_Settings::source_link_fields();
		foreach ( $link_fields as $index => $field_name ) {
			$source = isset( $sources[ $index ] ) ? $sources[ $index ] : null;

			if ( null === $source || '' === trim( $source['url'] ) ) {
				update_field( $field_name, '', $post_id );
				continue;
			}

			$label = trim( $source['title'] );
			if ( '' !== trim( $source['outlet'] ) ) {
				$label .= ' — ' . trim( $source['outlet'] );
			}

			update_field(
				$field_name,
				array(
					'title'  => $label,
					'url'    => $source['url'],
					'target' => '_blank',
				),
				$post_id
			);
		}
	}
}
