<?php
/**
 * Turns a raw caption track into publishable editorial material.
 *
 * @package YT_To_WP_Transcription
 */

defined( 'ABSPATH' ) || exit;

/**
 * One call to the Claude API per video. The job is formatting rather than
 * reasoning — fix misheard proper nouns, cut the talk into sections, write a
 * lede — so the cheapest current model handles it well.
 */
class YTWP_Claude {

	const ENDPOINT    = 'https://api.anthropic.com/v1/messages';
	const API_VERSION = '2023-06-01';

	/**
	 * Long videos run to tens of thousands of tokens in and several thousand
	 * out, which can take well over a minute.
	 */
	const TIMEOUT = 180;

	/**
	 * Ask the model for the editorial layer.
	 *
	 * @param array  $video        Video data from YTWP_YouTube.
	 * @param string $transcript   Timestamped transcript text.
	 * @param bool   $is_automatic Whether the caption track was machine made.
	 * @return array|WP_Error
	 */
	public static function draft( array $video, $transcript, $is_automatic ) {
		$api_key = (string) YTWP_Settings::get( 'anthropic_api_key', '' );
		if ( '' === $api_key ) {
			return new WP_Error(
				'ytwp_missing_anthropic_key',
				__( 'No Anthropic API key is saved.', 'yt-to-wp-transcription' )
			);
		}

		$payload = array(
			'model'       => (string) YTWP_Settings::get( 'anthropic_model', 'claude-haiku-4-5' ),
			'max_tokens'  => 16000,
			'system'      => self::system_prompt(),
			'tools'       => array( self::tool() ),
			'tool_choice' => array(
				'type' => 'tool',
				'name' => 'submit_article',
			),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => self::user_prompt( $video, $transcript, $is_automatic ),
				),
			),
		);

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => self::API_VERSION,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$detail = isset( $body['error']['message'] ) ? $body['error']['message'] : wp_remote_retrieve_body( $response );
			return new WP_Error(
				'ytwp_claude_error',
				sprintf(
					/* translators: 1: HTTP status, 2: error message. */
					__( 'The Claude API returned HTTP %1$d: %2$s', 'yt-to-wp-transcription' ),
					$code,
					wp_strip_all_tags( substr( (string) $detail, 0, 400 ) )
				)
			);
		}

		foreach ( ( isset( $body['content'] ) ? $body['content'] : array() ) as $block ) {
			if ( isset( $block['type'] ) && 'tool_use' === $block['type'] && is_array( $block['input'] ) ) {
				return array(
					'draft' => self::normalize( $block['input'] ),
					'usage' => isset( $body['usage'] ) ? $body['usage'] : array(),
				);
			}
		}

		return new WP_Error(
			'ytwp_claude_no_output',
			sprintf(
				/* translators: %s: stop reason reported by the API. */
				__( 'The model returned no article (stop reason: %s).', 'yt-to-wp-transcription' ),
				isset( $body['stop_reason'] ) ? $body['stop_reason'] : 'unknown'
			)
		);
	}

	/**
	 * Fill in anything the model left out so callers can trust the shape.
	 *
	 * @param array $input Tool input.
	 * @return array
	 */
	private static function normalize( array $input ) {
		return array(
			'title'        => isset( $input['title'] ) ? (string) $input['title'] : '',
			'lede'         => isset( $input['lede'] ) && is_array( $input['lede'] ) ? $input['lede'] : array(),
			'key_points'   => isset( $input['key_points'] ) && is_array( $input['key_points'] ) ? $input['key_points'] : array(),
			'sections'     => isset( $input['sections'] ) && is_array( $input['sections'] ) ? $input['sections'] : array(),
			'sources'      => isset( $input['sources'] ) && is_array( $input['sources'] ) ? $input['sources'] : array(),
			'excluded'     => isset( $input['excluded'] ) && is_array( $input['excluded'] ) ? $input['excluded'] : array(),
			'needs_review' => isset( $input['needs_review'] ) && is_array( $input['needs_review'] ) ? $input['needs_review'] : array(),
		);
	}

	/**
	 * The schema the model must fill. `strict` makes the API guarantee the
	 * arguments validate, so the caller never has to repair loose JSON.
	 *
	 * @return array
	 */
	private static function tool() {
		return array(
			'name'         => 'submit_article',
			'description'  => 'Submit the finished article built from the episode transcript.',
			'strict'       => true,
			'input_schema' => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'title', 'lede', 'key_points', 'sections', 'sources', 'excluded', 'needs_review' ),
				'properties'           => array(
					'title'        => array(
						'type'        => 'string',
						'description' => 'Headline in sentence case, at most 90 characters, naming the two or three biggest topics.',
					),
					'lede'         => array(
						'type'        => 'array',
						'description' => 'Two opening paragraphs, 120 to 200 words in total, in your own prose.',
						'items'       => array( 'type' => 'string' ),
					),
					'key_points'   => array(
						'type'        => 'array',
						'description' => 'Four to six one-sentence takeaways carrying the concrete figures and names.',
						'items'       => array( 'type' => 'string' ),
					),
					'sections'     => array(
						'type'        => 'array',
						'description' => 'The edited transcript, split into topical sections in the order they were spoken.',
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'heading', 'timestamp', 'paragraphs' ),
							'properties'           => array(
								'heading'    => array( 'type' => 'string' ),
								'timestamp'  => array(
									'type'        => 'string',
									'description' => 'MM:SS where the section begins, taken from the markers in the transcript.',
								),
								'paragraphs' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
						),
					),
					'sources'      => array(
						'type'        => 'array',
						'description' => 'Outlets credited in the episode. Use an empty string when no URL was given.',
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'title', 'outlet', 'url' ),
							'properties'           => array(
								'title'  => array( 'type' => 'string' ),
								'outlet' => array( 'type' => 'string' ),
								'url'    => array( 'type' => 'string' ),
							),
						),
					),
					'excluded'     => array(
						'type'        => 'array',
						'description' => 'Ad reads and filler removed from the transcript, each with its timestamp.',
						'items'       => array( 'type' => 'string' ),
					),
					'needs_review' => array(
						'type'        => 'array',
						'description' => 'Anything an editor must check before publishing.',
						'items'       => array( 'type' => 'string' ),
					),
				),
			),
		);
	}

	/**
	 * Editing rules. The transcript is the publisher's own recording, so the
	 * task is to make it readable, never to rewrite or embellish it.
	 *
	 * @return string
	 */
	private static function system_prompt() {
		$prompt = "You are a news editor preparing a published article from the transcript of a video the publication recorded itself. The spoken words are the journalist's own reporting. Your job is to make them readable, never to rewrite them or add anything they did not say.\n\n";

		$prompt .= "EDITING RULES\n\n";
		$prompt .= "1. Correct proper nouns, institutions and technical terms that speech recognition got wrong. Use the surrounding context to work out what was meant. Company names, place names and people's names are where these errors do the most damage.\n\n";
		$prompt .= "2. Remove filler words, false starts and repeated words, but keep the speaker's voice: their register, their idiom, their turns of phrase. Do not make them sound formal or neutral.\n\n";
		$prompt .= "3. Split the episode into topical sections. Give each a short descriptive heading and the MM:SS timestamp where it begins, taken from the markers in the transcript you receive. Never invent a timestamp.\n\n";
		$prompt .= "4. Never turn an allegation, a rumour or someone else's reporting into a statement of fact. Keep the attributions intact: \"according to reports\", \"he says\", \"they claim\". This matters most when a named person is involved.\n\n";
		$prompt .= "5. Add no facts, figures, dates or context that are not in the transcript. When something is ambiguous, leave it and note it in needs_review.\n\n";

		$prompt .= "WHAT TO LEAVE OUT\n\n";
		$prompt .= "Drop sponsor reads and advertising from the sections: they are not editorial content. Record each one in excluded with its timestamp and what it was. Drop opening greetings and sign-offs that carry nothing.\n\n";

		$prompt .= "WHAT YOU WRITE YOURSELF\n\n";
		$prompt .= "The headline, the lede and the key points are yours. Everything in the sections is the speaker, edited. The video description often lists the stories covered along with the outlet that reported each one: use it for the sources, and for nothing else.\n\n";
		$prompt .= "Write in the same language the transcript is in.";

		$notes = trim( (string) YTWP_Settings::get( 'editorial_notes', '' ) );
		if ( '' !== $notes ) {
			$prompt .= "\n\nHOUSE NOTES\n\n" . $notes;
		}

		return $prompt;
	}

	/**
	 * The episode itself.
	 *
	 * @param array  $video        Video data.
	 * @param string $transcript   Timestamped transcript.
	 * @param bool   $is_automatic Whether captions were machine made.
	 * @return string
	 */
	private static function user_prompt( array $video, $transcript, $is_automatic ) {
		$description = '' !== trim( $video['description'] ) ? $video['description'] : '(no description)';

		$lines = array(
			'VIDEO TITLE: ' . $video['title'],
			'PUBLISHED: ' . $video['published_at'],
			'DURATION: ' . $video['duration_iso'],
			'',
			'VIDEO DESCRIPTION (often lists the stories and the outlet that reported each):',
			$description,
			'',
			$is_automatic
				? 'AUTOMATIC TRANSCRIPT (speech recognition, so expect misheard names — correct them):'
				: 'TRANSCRIPT (hand-uploaded caption track):',
			$transcript,
		);

		return implode( "\n", $lines );
	}
}
