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

		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => $api_key,
			'anthropic-version' => self::API_VERSION,
		);

		// An organization-wide key is not tied to a workspace, so the API
		// rejects it unless the request names the workspace to bill and
		// scope to. A workspace-scoped key carries that itself and needs
		// no header, which is why this stays optional.
		$workspace = trim( (string) YTWP_Settings::get( 'anthropic_workspace_id', '' ) );
		if ( '' !== $workspace ) {
			$headers['anthropic-workspace-id'] = $workspace;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $headers,
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
			// Absent means "nobody said otherwise", and treating that as a
			// solo episode keeps a missing field from silently binning a
			// video that was fine.
			'single_speaker' => ! isset( $input['single_speaker'] ) || (bool) $input['single_speaker'],
			'other_speakers' => isset( $input['other_speakers'] ) && is_array( $input['other_speakers'] ) ? $input['other_speakers'] : array(),
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
			'description'  => 'Submit the finished news article.',
			'strict'       => true,
			'input_schema' => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'title', 'lede', 'key_points', 'sections', 'sources', 'excluded', 'needs_review', 'single_speaker', 'other_speakers' ),
				'properties'           => array(
					'title'        => array(
						'type'        => 'string',
						'description' => 'Short headline in sentence case, about four words, built around the biggest story. Never more than eight words.',
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
						'description' => 'The body of the article, one section per story covered, written as prose.',
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'heading', 'paragraphs' ),
							'properties'           => array(
								'heading'    => array(
									'type'        => 'string',
									'description' => 'A short headline for this story, the way a section heading reads in a newspaper.',
								),
								'paragraphs' => array(
									'type'        => 'array',
									'description' => 'Two to five paragraphs telling this story.',
									'items'       => array( 'type' => 'string' ),
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
					'single_speaker' => array(
						'type'        => 'boolean',
						'description' => 'True when the journalist is the only person speaking. False when anyone else takes a turn: a guest, a co-host, a panel, an interviewee.',
					),
					'other_speakers' => array(
						'type'        => 'array',
						'description' => 'The names of anyone else who speaks, as best you can tell from the transcript. Empty when the journalist speaks alone.',
						'items'       => array( 'type' => 'string' ),
					),
				),
			),
		);
	}

	/**
	 * The language the article must be written in, named in full. A code
	 * like "es" is easy for a model to skim past; the name is not.
	 *
	 * @param string $code Two-letter language code.
	 * @return string
	 */
	public static function language_name( $code ) {
		$names = array(
			'en' => 'English',
			'es' => 'Spanish',
			'pt' => 'Portuguese',
			'fr' => 'French',
			'it' => 'Italian',
			'de' => 'German',
			'ca' => 'Catalan',
			'gl' => 'Galician',
			'nl' => 'Dutch',
			'ro' => 'Romanian',
			'pl' => 'Polish',
			'tr' => 'Turkish',
			'ja' => 'Japanese',
			'ko' => 'Korean',
			'zh' => 'Chinese',
			'ar' => 'Arabic',
			'hi' => 'Hindi',
			'ru' => 'Russian',
		);

		$key = strtolower( substr( (string) $code, 0, 2 ) );

		return isset( $names[ $key ] ) ? $names[ $key ] : $key;
	}

	/**
	 * Editing rules. The transcript is the publisher's own recording, so the
	 * task is to make it readable, never to rewrite or embellish it.
	 *
	 * @return string
	 */
	private static function system_prompt() {
		$language = self::language_name( YTWP_Settings::get( 'caption_language', 'en' ) );

		$prompt  = "You write for a news outlet, in the voice of its lead journalist. You are given the transcript of a broadcast that journalist recorded, and you turn it into the written article covering the same stories.\n\n";

		$prompt .= "LANGUAGE — THIS OVERRIDES EVERYTHING ELSE\n\n";
		$prompt .= "The broadcast is in {$language} and the article is published in {$language}. Write every word you produce in {$language}: the headline, the opening, the key points, every section heading, every paragraph, the source titles and the review notes.\n\n";
		$prompt .= "Never translate. These instructions are written in English for convenience; that is not the language of the article. If you find yourself writing in English, you have made the single worst mistake available to you here.\n\n";

		$prompt .= "WHAT YOU ARE WRITING\n\n";
		$prompt .= "A news article, not a transcript. Nobody should be able to tell it started as speech. Never refer to the video, the episode, the broadcast, the recording or the transcript, and never write anything like 'in this episode', 'as I said', 'the host explained' or a timestamp.\n\n";
		$prompt .= "Take each story the journalist covered and tell it properly, in order of weight rather than the order it was spoken. Give each story its own section with a short heading. Two to five paragraphs each. Where he moved on after half a sentence, that is a line inside another section, not a section of its own.\n\n";

		$prompt .= "THE VOICE\n\n";
		$prompt .= "It must read as though he sat down and wrote it. Study how he talks in the transcript and write that way: his register, his vocabulary, his regional idiom, the way he addresses the reader, his bluntness, his humour, the asides he makes when a number does not add up. Keep the first person where he is speaking as himself, including his own judgements and opinions, because they are his. Do not smooth him into neutral news-agency prose and do not make him more formal than he is.\n\n";
		$prompt .= "What changes is the form, not the person: full sentences, a clear order, no filler, no false starts, no repetition. What he actually said stays exactly as true as it was.\n\n";

		$prompt .= "RULES YOU DO NOT BREAK\n\n";
		$prompt .= "1. Invent nothing. No fact, figure, name, date, quote or context that is not in the transcript. Writing in his voice is not permission to put words in his mouth. If something is unclear, leave it out and note it in needs_review.\n\n";
		$prompt .= "2. Keep every attribution. What he presents as a report, a rumour, an allegation or someone else's claim stays that way in writing: 'according to', 'he says', 'they claim'. A statement of fact is only for what he states as fact himself. This matters most when a named person is involved, and an article in his voice carries more weight than loose speech, so the line matters more here, not less.\n\n";
		$prompt .= "3. Fix what speech recognition got wrong: names of people, places, companies and institutions, and technical terms. Use the surrounding context to work out what was meant.\n\n";
		$prompt .= "4. Leave out sponsor reads, advertising, greetings and sign-offs. Record each one in excluded with roughly where it was.\n\n";

		$prompt .= "THE PARTS\n\n";
		$prompt .= "- title: a short, punchy headline in sentence case. Aim for four words, built around the single biggest story. Go a word or two longer only when four cannot say it clearly; never more than eight.\n";
		$prompt .= "- lede: two opening paragraphs, 120 to 200 words in total, telling the reader what happened and why it matters.\n";
		$prompt .= "- key_points: four to six single sentences carrying the concrete figures and names.\n";
		$prompt .= "- sections: the body, one per story.\n";
		$prompt .= "- sources: the outlets credited. The video description usually lists each story with the outlet that reported it; use it for this and nothing else.\n";
		$prompt .= "- needs_review: what an editor must check before publishing. Names you could not resolve with confidence, figures that sound mistranscribed, and anything said about a named person.\n";
		$prompt .= "- single_speaker and other_speakers: whether the journalist is the only person speaking. Set single_speaker to false the moment anyone else takes a turn - a guest, a co-host, a panel, someone being interviewed - and name them in other_speakers. Judge this from the transcript itself: someone being greeted by name and handed the floor, thanked for being there, or answering a question put to them. A name merely being discussed is not a second speaker. Fill these in honestly even when the article turned out well; what happens to the article afterwards is not your decision.\n\n";

		$prompt .= "Before you submit, reread what you wrote. Confirm every field is in {$language}, that it reads as writing rather than speech, and that nothing appears in it that was not in the transcript.";

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
		$language    = self::language_name( YTWP_Settings::get( 'caption_language', 'en' ) );

		$lines = array(
			"WRITE THE ENTIRE ARTICLE IN {$language}. Do not translate the speaker.",
			'',
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
