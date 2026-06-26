<?php
/**
 * Plugin Name: Autolink Text
 * Description: Automatically links and styles configured terms in completed Gutenberg paragraphs through a PHP-only Gutenberg RTC bot.
 * Version: 0.1.0
 * Requires Plugins: gutenberg
 * Author: Alex Kirk
 * Text Domain: autolink-text
 *
 * @package Autolink_Text
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const AUTO_LINKER_OPTION_BOT_USER_ID = 'auto_linker_bot_user_id';
const AUTO_LINKER_OPTION_TERMS = 'auto_linker_terms';
const AUTO_LINKER_ROOM_STATE_META_KEY = '_auto_linker_room_state';
const AUTO_LINKER_ROOM_STATE_SCHEMA_VERSION = 9;
const AUTO_LINKER_MAX_YDOC_STATE_BYTES = 524288;
const AUTO_LINKER_MAX_YDOC_STATE_BASE64_LENGTH = 699052;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/gutenberg-yjs-update-v2.php';
require_once __DIR__ . '/includes/gutenberg-rtc-debug-log.php';
require_once __DIR__ . '/includes/autolink-text-support.php';

add_action( 'admin_init', 'auto_linker_register_settings' );
add_action( 'admin_menu', 'auto_linker_register_settings_page' );
add_action( 'admin_enqueue_scripts', 'auto_linker_enqueue_settings_assets' );
add_filter( 'rest_pre_dispatch', array( 'Autolink_Text_Bot', 'log_wp_sync_requests' ), 10, 3 );
add_filter( 'rest_post_dispatch', array( 'Autolink_Text_Bot', 'respond_to_wp_sync_requests' ), 10, 3 );

/*
 * This is how the bot works:
 *
 * 1. When someone edits a post in Gutenberg real-time collaboration, Gutenberg
 *    sends the collaboration events to /wp-sync/v1/updates. Each edited post is
 *    represented as a Yjs/Gutenberg "room", such as postType/post:123 for post
 *    ID 123. WordPress calls auto_linker_log_wp_sync_requests() before handling
 *    that REST request, so Autolink Text can log enough detail to debug the room
 *    traffic.
 * 2. After WordPress accepts the REST request, WordPress calls
 *    auto_linker_respond_to_wp_sync_requests(). Autolink Text runs as a bot user
 *    in the same collaboration session, so it skips requests from its own bot
 *    client, then passes each post room's updates to
 *    auto_linker_ydoc_handle_room_updates().
 * 3. It rebuilds the post's YDoc from the last saved YDoc state and the newest
 *    Gutenberg updates, then asks
 *    auto_linker_ydoc_emit_first_link() whether the bot should make one edit.
 * 4. It decides where to edit: it prefers the paragraph where the collaborator
 *    is currently typing, and falls back to scanning the document for the newest
 *    paragraph with a configured term.
 * 5. auto_linker_ydoc_candidate_match() checks whether that YDoc text candidate
 *    still has a configured, unlinked term, including serialized paragraph
 *    HTML fallbacks.
 * 6. When a term can be linked, auto_linker_ydoc_apply_link_candidate() inserts
 *    the opening and closing anchor text into the YDoc, sends that bot-authored
 *    update back to /wp-sync/v1/updates, and briefly selects the linked term as
 *    the bot.
 * 7. auto_linker_ydoc_handle_room_updates() saves the new YDoc state, and
 *    auto_linker_append_bot_update_to_response() also adds the bot-authored
 *    update to the current REST response so the active editor sees it right
 *    away.
 */

/**
 * Handles the Gutenberg RTC bot workflow for Autolink Text.
 */
final class Autolink_Text_Bot {

/**
 * Logs incoming Gutenberg real-time collaboration requests before WordPress
 * handles them.
 *
 * See step 1 above.
 *
 * @param mixed           $result  Response to replace requested version with.
 * @param WP_REST_Server  $server  Server instance.
 * @param WP_REST_Request $request Request used to generate the response.
 * @return mixed Unchanged response.
 */
	public static function log_wp_sync_requests( $result, WP_REST_Server $server, WP_REST_Request $request ) {
	unset( $server );

	if ( '/wp-sync/v1/updates' !== $request->get_route() || 'POST' !== $request->get_method() ) {
		return $result;
	}

	$rooms = \Autolink_Text\Gutenberg_RTC\gutenberg_rtc_get_request_rooms( $request );
	if ( ! is_array( $rooms ) ) {
		auto_linker_log(
			'wp-sync-request',
			array(
				'route'        => $request->get_route(),
				'method'       => $request->get_method(),
				'content_type' => $request->get_header( 'content-type' ),
				'body_length'  => strlen( (string) $request->get_body() ),
				'error'        => 'missing_rooms',
			)
		);
		return $result;
	}

	auto_linker_log(
		'wp-sync-request',
		array(
			'route'        => $request->get_route(),
			'method'       => $request->get_method(),
			'content_type' => $request->get_header( 'content-type' ),
			'body_length'  => strlen( (string) $request->get_body() ),
			'room_count'   => count( $rooms ),
			'rooms'        => \Autolink_Text\Gutenberg_RTC\gutenberg_rtc_summarize_rooms( $rooms ),
		)
	);

	auto_linker_maybe_emit_bot_awareness_nudges_for_rooms( $rooms );
	\Autolink_Text\Gutenberg_RTC\gutenberg_rtc_decode_rooms_for_logging( $rooms, 'auto_linker_log' );

	return $result;
}

/**
 * Handles accepted Gutenberg collaboration updates and queues any bot-authored
 * edit.
 *
 * See step 2 above.
 *
 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Response.
 * @param WP_REST_Server                                   $server   Server instance.
 * @param WP_REST_Request                                  $request  Request.
 * @return mixed Unchanged response.
 */
	public static function respond_to_wp_sync_requests( $response, WP_REST_Server $server, WP_REST_Request $request ) {
	unset( $server );

	if ( '/wp-sync/v1/updates' !== $request->get_route() || 'POST' !== $request->get_method() ) {
		return $response;
	}

	$rooms = \Autolink_Text\Gutenberg_RTC\gutenberg_rtc_get_request_rooms( $request );
	if ( ! is_array( $rooms ) ) {
		return $response;
	}

	$bot_user_id = auto_linker_get_bot_user_id();
	$bot_client  = $bot_user_id ? auto_linker_get_bot_client_id( $bot_user_id ) : 0;

	foreach ( $rooms as $room_request ) {
		if ( ! is_array( $room_request ) ) {
			continue;
		}

		$client_id = isset( $room_request['client_id'] ) ? (int) $room_request['client_id'] : 0;
		if ( $bot_client && $client_id === $bot_client ) {
			continue;
		}

		$room    = isset( $room_request['room'] ) && is_string( $room_request['room'] ) ? $room_request['room'] : '';
		$post_id = auto_linker_get_post_id_from_room( $room );
		$updates = isset( $room_request['updates'] ) && is_array( $room_request['updates'] ) ? $room_request['updates'] : array();

		if ( ! $post_id ) {
			continue;
		}

		$updates_to_apply = array_merge(
			auto_linker_response_updates_for_room( $response, $room ),
			$updates
		);

		foreach ( self::handle_room_updates( $post_id, $room, $updates_to_apply, $room_request ) as $bot_update ) {
			$response = self::append_bot_update_to_response( $response, $room, $bot_update );
		}
	}

	return $response;
}

/**
 * Rebuilds one post room's YDoc, lets the bot make at most one link edit, and
 * saves the new state.
 *
 * See steps 3 and 7 above.
 *
 * @param array<int,array<string,mixed>> $updates      Incoming sync updates.
 * @param array<string,mixed>            $room_request Current sync room request.
 * @return array<int,array<string,mixed>>
 */
	public static function handle_room_updates( int $post_id, string $room, array $updates, array $room_request = array() ): array {
	$state = auto_linker_get_room_state( $post_id );
	$doc   = auto_linker_ydoc_from_state( $post_id, $state );
	if ( array() === $doc->toJSON() ) {
		$bootstrap_updates = auto_linker_fetch_room_snapshot_updates( $post_id, $room );
		if ( $bootstrap_updates ) {
			$updates = array_merge( $bootstrap_updates, $updates );
		}
	}

	foreach ( $updates as $update ) {
		auto_linker_ydoc_apply_room_update( $doc, $update, $room );
	}

	$bot_updates = array();
	$bot_update  = self::emit_first_link( $post_id, $room, $doc, $state, $room_request );
	if ( is_array( $bot_update ) ) {
		$bot_updates[] = $bot_update;
	}

	$state_update = $doc->encodeStateAsUpdateV2();
	if ( strlen( $state_update ) > AUTO_LINKER_MAX_YDOC_STATE_BYTES ) {
		auto_linker_log(
			'bot-rtc-ydoc-state-reset',
			array(
				'post_id' => $post_id,
				'room'    => $room,
				'reason'  => 'encoded_state_too_large',
				'bytes'   => strlen( $state_update ),
			)
		);
		$state['ydoc_update_v2'] = '';
		$state['pending_link']   = null;
		$state['selection_until'] = 0;
	} else {
		$state['ydoc_update_v2'] = base64_encode( $state_update );
	}
	auto_linker_set_room_state( $post_id, $state );

	return $bot_updates;
}

/**
 * Chooses the paragraph the bot should edit and links the first configured term
 * it finds.
 *
 * See step 4 above.
 *
 * @return array<string,mixed>|null
 */
	public static function emit_first_link( int $post_id, string $room, \Yjs\YDoc $doc, array &$state, array $room_request = array() ): ?array {
	if ( ! $post_id || '' === $room ) {
		return null;
	}

	$bot_user_id = auto_linker_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return null;
	}

	$bot_user = get_user_by( 'id', $bot_user_id );
	if ( ! $bot_user || ! user_can( $bot_user, 'edit_post', $post_id ) ) {
		auto_linker_log(
			'bot-rtc-ydoc-skip',
			array(
				'room'   => $room,
				'reason' => 'bot_cannot_edit',
			)
		);
		return null;
	}

	if ( ! empty( $state['pending_link'] ) ) {
		unset( $state['pending_link'] );
	}

	$candidate = auto_linker_ydoc_find_awareness_link_candidate( $doc, $room_request );
	if ( $candidate && ! self::candidate_match( $candidate ) ) {
		auto_linker_log(
			'bot-rtc-ydoc-candidate-skip',
			array_merge(
				array(
					'room'   => $room,
					'path'   => $candidate['path'],
					'reason' => 'awareness_candidate_has_no_linkable_term',
					'text'   => $candidate['text'],
				),
				auto_linker_ydoc_link_candidate_diagnostics( $doc, $room_request )
			)
		);
		$candidate = null;
	}

	$candidate = $candidate ?? auto_linker_ydoc_find_first_link_candidate( $doc );
	if ( ! $candidate ) {
		auto_linker_log(
			'bot-rtc-ydoc-skip',
			array_merge(
				array(
					'room'   => $room,
					'reason' => 'no_link_candidate',
				),
				auto_linker_ydoc_link_candidate_diagnostics( $doc, $room_request )
			)
		);
		return null;
	}

	$text  = $candidate['text'];
	$match = self::candidate_match( $candidate );
	auto_linker_log(
		'bot-rtc-ydoc-candidate',
		array(
			'room' => $room,
			'path' => $candidate['path'],
			'text' => $text,
		)
	);
	if ( ! $match ) {
		return null;
	}

	return self::apply_link_candidate( $post_id, $room, $doc, $bot_user, $candidate, $state );
}

/**
 * Finds a linkable match for a YDoc text candidate.
 *
 * @param array{text:string,path?:string,match_mode?:string} $candidate Candidate metadata.
 * @return array{term:string,url:string,color:string,bg_color:string,bold:bool,matched_text:string,start:int,length:int,replacement:string,opening_text:string,closing_text:string}|null
 */
	public static function candidate_match( array $candidate ): ?array {
	$text       = (string) ( $candidate['text'] ?? '' );
	$match_mode = (string) ( $candidate['match_mode'] ?? '' );
	if ( '' === $match_mode && str_contains( $text, '<!-- wp:' ) && str_contains( $text, '<p' ) ) {
		$match_mode = 'serialized_paragraph_html';
	}

	return 'serialized_paragraph_html' === $match_mode
		? self::find_first_serialized_paragraph_term( $text, auto_linker_get_terms() )
		: self::find_first_unlinked_term( $text, auto_linker_get_terms() );
}

/**
 * Finds the first configured term that is not already inside markup.
 *
 * @param array<int,array{term:string,url:string,color?:string,bg_color?:string,bold?:bool}> $terms Terms.
 * @return array{term:string,url:string,color:string,bg_color:string,bold:bool,matched_text:string,start:int,length:int,replacement:string,opening_text:string,closing_text:string}|null
 */
	public static function find_first_unlinked_term( string $text, array $terms ): ?array {
	if ( ! self::has_balanced_anchor_markup( $text ) ) {
		return null;
	}

	foreach ( $terms as $term ) {
		$label = (string) ( $term['term'] ?? '' );
		$url   = (string) ( $term['url'] ?? '' );
		$color = auto_linker_sanitize_link_color( (string) ( $term['color'] ?? '' ) );
		$bg_color = auto_linker_sanitize_link_color( (string) ( $term['bg_color'] ?? '' ) );
		$bold  = ! empty( $term['bold'] );
		if ( '' === $label || '' === $url ) {
			continue;
		}

		$pattern = '/(?<![\p{L}\p{N}_])(' . preg_quote( $label, '/' ) . ')(?=[^\p{L}\p{N}_])/iu';
		if ( ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		foreach ( $matches[1] as $match ) {
			$matched_text = (string) $match[0];
			$byte_offset  = (int) $match[1];
			if ( self::offset_is_inside_markup( $text, $byte_offset ) ) {
				continue;
			}

			return array(
				'term'         => $label,
				'url'          => $url,
				'color'        => $color,
				'bg_color'     => $bg_color,
				'bold'         => $bold,
				'matched_text' => $matched_text,
				'start'        => \Autolink_Text\Gutenberg_RTC\gutenberg_yjs_utf16_clock_len( substr( $text, 0, $byte_offset ) ),
				'length'       => \Autolink_Text\Gutenberg_RTC\gutenberg_yjs_utf16_clock_len( $matched_text ),
				'replacement'  => self::build_anchor_html( $matched_text, $url, $color, $bg_color, $bold ),
				'opening_text' => self::build_opening_anchor_html( $url, $color, $bg_color, $bold ),
				'closing_text' => '</a>',
			);
		}
	}

	return null;
}

/**
 * Finds a configured term inside serialized paragraph HTML.
 *
 * @param array<int,array{term:string,url:string,color?:string,bg_color?:string,bold?:bool}> $terms Terms.
 * @return array{term:string,url:string,color:string,bg_color:string,bold:bool,matched_text:string,start:int,length:int,replacement:string,opening_text:string,closing_text:string}|null
 */
	public static function find_first_serialized_paragraph_term( string $text, array $terms ): ?array {
	if ( ! preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $text, $paragraphs, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}

	foreach ( $paragraphs[1] as $paragraph ) {
		$inner_html        = (string) $paragraph[0];
		$inner_byte_offset = (int) $paragraph[1];
		$match             = self::find_first_unlinked_term( $inner_html, $terms );
		if ( ! $match ) {
			continue;
		}

		$match['start'] += \Autolink_Text\Gutenberg_RTC\gutenberg_yjs_utf16_clock_len( substr( $text, 0, $inner_byte_offset ) );

		return $match;
	}

	return null;
}

/**
 * Checks whether reconstructed text has balanced anchor tags.
 */
	public static function has_balanced_anchor_markup( string $text ): bool {
	return substr_count( strtolower( $text ), '<a ' ) === substr_count( strtolower( $text ), '</a>' );
}

/**
 * Checks whether a byte offset is inside an HTML tag or anchor.
 */
	public static function offset_is_inside_markup( string $text, int $byte_offset ): bool {
	$before     = substr( $text, 0, $byte_offset );
	$last_open  = strrpos( $before, '<' );
	$last_close = strrpos( $before, '>' );
	if ( false !== $last_open && ( false === $last_close || $last_open > $last_close ) ) {
		return true;
	}

	$lower              = strtolower( $before );
	$last_anchor_open   = strrpos( $lower, '<a ' );
	$last_empty_anchor  = strrpos( $lower, '<a>' );
	$last_anchor_close  = strrpos( $lower, '</a>' );
	$last_anchor_offset = max(
		false === $last_anchor_open ? -1 : $last_anchor_open,
		false === $last_empty_anchor ? -1 : $last_empty_anchor
	);

	return $last_anchor_offset > ( false === $last_anchor_close ? -1 : $last_anchor_close );
}

/**
 * Builds the anchor markup inserted into paragraph content.
 */
	public static function build_anchor_html( string $label, string $url, string $color = '', string $bg_color = '', bool $bold = false ): string {
	return self::build_opening_anchor_html( $url, $color, $bg_color, $bold ) . esc_html( $label ) . '</a>';
}

/**
 * Builds the opening anchor tag inserted before linked text.
 */
	public static function build_opening_anchor_html( string $url, string $color = '', string $bg_color = '', bool $bold = false ): string {
	$style = self::build_link_style( $color, $bg_color, $bold );

	return '<a href="' . esc_url( $url ) . '"' . ( '' === $style ? '' : ' style="' . esc_attr( $style ) . '"' ) . '>';
}

/**
 * Builds a constrained inline style string for generated links.
 */
	public static function build_link_style( string $color, string $bg_color, bool $bold ): string {
	$styles   = array();
	$color    = auto_linker_sanitize_link_color( $color );
	$bg_color = auto_linker_sanitize_link_color( $bg_color );

	if ( '' !== $color ) {
		$styles[] = 'color: ' . $color . ';';
	}

	if ( '' !== $bg_color ) {
		$styles[] = 'background-color: ' . $bg_color . ';';
	}

	if ( $bold ) {
		$styles[] = 'font-weight: 700;';
	}

	return implode( ' ', $styles );
}

/**
 * Inserts anchor markup into the YDoc and posts that bot-authored update back to
 * Gutenberg sync.
 *
 * See step 6 above.
 *
 * @param array{text_type:\Yjs\YNestedText,text:string,path:string,match_mode?:string} $candidate Link candidate.
 * @return array<string,mixed>|null
 */
	public static function apply_link_candidate( int $post_id, string $room, \Yjs\YDoc $doc, WP_User $bot_user, array $candidate, array &$state ): ?array {
	$text  = $candidate['text_type']->toString();
	$candidate['text'] = $text;
	$match = self::candidate_match( $candidate );
	if ( ! $match ) {
		auto_linker_log(
			'bot-rtc-ydoc-link',
			array(
				'ok'      => false,
				'room'    => $room,
				'path'    => $candidate['path'],
				'message' => 'No matching term remained after highlight.',
			)
		);
		return null;
	}

	$captured_update = null;
	$observer_id     = $doc->observeUpdateV2(
		static function ( string $update, \Yjs\YDoc $observed_doc, mixed $origin ) use ( &$captured_update ): void {
			unset( $observed_doc );
			if ( 'auto-linker' === $origin ) {
				$captured_update = $update;
			}
		}
	);

	$ytext = $candidate['text_type'];
	$doc->transact(
		static function () use ( $ytext, $match ): void {
			$start  = (int) $match['start'];
			$length = (int) $match['length'];
			$ytext->insert( $start + $length, (string) $match['closing_text'] );
			$ytext->insert( $start, (string) $match['opening_text'] );
		},
		'auto-linker'
	);
	$doc->unobserveUpdateV2( $observer_id );

	if ( null === $captured_update ) {
		auto_linker_log(
			'bot-rtc-ydoc-link',
			array(
				'ok'      => false,
				'room'    => $room,
				'term'    => $match['term'],
				'message' => 'No YDoc update was emitted.',
			)
		);
		return null;
	}

	$bot_client_id = auto_linker_get_bot_client_id( $bot_user->ID );
	$update_data   = base64_encode( $captured_update );
	$selection = auto_linker_build_ydoc_text_selection(
		$candidate['text_type'],
		(int) $match['start'] + strlen( (string) $match['opening_text'] ),
		(int) $match['length'],
		(int) $match['start'],
		(int) $match['start'] + (int) $match['length']
	);
	$awareness = $selection
		? auto_linker_build_bot_selection_awareness(
			$bot_user,
			$post_id,
			(string) $match['matched_text'],
			$selection
		)
		: auto_linker_build_bot_awareness_state( $bot_user, $post_id, (string) $match['matched_text'] );

	$result = auto_linker_post_bot_update( $post_id, $room, $bot_client_id, $update_data, $awareness );
	$ok     = ! is_wp_error( $result );
	auto_linker_log(
		'bot-rtc-ydoc-link',
		$ok
			? array(
				'ok'              => true,
				'room'            => $room,
				'path'            => $candidate['path'],
				'term'            => $match['term'],
				'matched_text'    => $match['matched_text'],
				'replacement'     => $match['replacement'],
				'selection'        => $selection,
				'selection_mode'   => 'raw_word_positions_visible_offsets',
				'update_bytes'    => strlen( $captured_update ),
				'response_status' => $result->get_status(),
			)
			: array(
				'ok'      => false,
				'room'    => $room,
				'term'    => $match['term'],
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			)
	);

	if ( ! $ok ) {
		return null;
	}

	$state['selection_until'] = time() + 4;

	return array(
		'ok'               => true,
		'bot_client_id'    => $bot_client_id,
		'update_data'      => $update_data,
		'update_bytes'     => strlen( $captured_update ),
		'term'             => $match['term'],
		'matched_text'     => $match['matched_text'],
		'replacement'      => $match['replacement'],
		'response_status'  => $result->get_status(),
		'response_payload' => $result->get_data(),
	);
}

/**
 * Adds the bot-authored YDoc update to the current REST response for the active
 * editor.
 *
 * See step 7 above.
 *
 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Response.
 * @param array<string, mixed>                             $bot_update Bot update metadata.
 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|mixed
 */
	public static function append_bot_update_to_response( $response, string $room, array $bot_update ) {
	if ( ! $response instanceof WP_REST_Response || empty( $bot_update['update_data'] ) ) {
		return $response;
	}

	$data = $response->get_data();
	if ( ! is_array( $data ) || empty( $data['rooms'] ) || ! is_array( $data['rooms'] ) ) {
		return $response;
	}

	foreach ( $data['rooms'] as &$room_response ) {
		if ( ! is_array( $room_response ) || (string) ( $room_response['room'] ?? '' ) !== $room ) {
			continue;
		}

		if ( ! isset( $room_response['updates'] ) || ! is_array( $room_response['updates'] ) ) {
			$room_response['updates'] = array();
		}

		$room_response['updates'][] = array(
			'type' => 'update',
			'data' => (string) $bot_update['update_data'],
		);

		if ( isset( $bot_update['response_payload']['rooms'] ) && is_array( $bot_update['response_payload']['rooms'] ) ) {
			foreach ( $bot_update['response_payload']['rooms'] as $bot_room_response ) {
				if ( is_array( $bot_room_response ) && (string) ( $bot_room_response['room'] ?? '' ) === $room && isset( $bot_room_response['end_cursor'] ) ) {
					$room_response['end_cursor'] = $bot_room_response['end_cursor'];
					break;
				}
			}
		}

		break;
	}
	unset( $room_response );

	$response->set_data( $data );
	return $response;
}

}

/**
 * Back-compat wrapper for the REST pre-dispatch hook callback.
 *
 * @return mixed
 */
function auto_linker_log_wp_sync_requests( $result, WP_REST_Server $server, WP_REST_Request $request ) {
	return Autolink_Text_Bot::log_wp_sync_requests( $result, $server, $request );
}

/**
 * Back-compat wrapper for the REST post-dispatch hook callback.
 *
 * @return mixed
 */
function auto_linker_respond_to_wp_sync_requests( $response, WP_REST_Server $server, WP_REST_Request $request ) {
	return Autolink_Text_Bot::respond_to_wp_sync_requests( $response, $server, $request );
}

/**
 * Back-compat wrapper for YDoc room update handling.
 *
 * @param array<int,array<string,mixed>> $updates      Incoming sync updates.
 * @param array<string,mixed>            $room_request Current sync room request.
 * @return array<int,array<string,mixed>>
 */
function auto_linker_ydoc_handle_room_updates( int $post_id, string $room, array $updates, array $room_request = array() ): array {
	return Autolink_Text_Bot::handle_room_updates( $post_id, $room, $updates, $room_request );
}

/**
 * Back-compat wrapper for YDoc link emission.
 *
 * @return array<string,mixed>|null
 */
function auto_linker_ydoc_emit_first_link( int $post_id, string $room, \Yjs\YDoc $doc, array &$state, array $room_request = array() ): ?array {
	return Autolink_Text_Bot::emit_first_link( $post_id, $room, $doc, $state, $room_request );
}

/**
 * Back-compat wrapper for candidate matching.
 *
 * @param array{text:string,path?:string,match_mode?:string} $candidate Candidate metadata.
 * @return array{term:string,url:string,color:string,bg_color:string,bold:bool,matched_text:string,start:int,length:int,replacement:string,opening_text:string,closing_text:string}|null
 */
function auto_linker_ydoc_candidate_match( array $candidate ): ?array {
	return Autolink_Text_Bot::candidate_match( $candidate );
}

/**
 * Back-compat wrapper for term matching.
 *
 * @param array<int,array{term:string,url:string,color?:string,bg_color?:string,bold?:bool}> $terms Terms.
 * @return array{term:string,url:string,color:string,bg_color:string,bold:bool,matched_text:string,start:int,length:int,replacement:string,opening_text:string,closing_text:string}|null
 */
function auto_linker_find_first_unlinked_term( string $text, array $terms ): ?array {
	return Autolink_Text_Bot::find_first_unlinked_term( $text, $terms );
}

/**
 * Back-compat wrapper for serialized paragraph term matching.
 *
 * @param array<int,array{term:string,url:string,color?:string,bg_color?:string,bold?:bool}> $terms Terms.
 * @return array{term:string,url:string,color:string,bg_color:string,bold:bool,matched_text:string,start:int,length:int,replacement:string,opening_text:string,closing_text:string}|null
 */
function auto_linker_find_first_serialized_paragraph_term( string $text, array $terms ): ?array {
	return Autolink_Text_Bot::find_first_serialized_paragraph_term( $text, $terms );
}

/**
 * Back-compat wrapper for anchor balance checks.
 */
function auto_linker_has_balanced_anchor_markup( string $text ): bool {
	return Autolink_Text_Bot::has_balanced_anchor_markup( $text );
}

/**
 * Back-compat wrapper for markup offset checks.
 */
function auto_linker_offset_is_inside_markup( string $text, int $byte_offset ): bool {
	return Autolink_Text_Bot::offset_is_inside_markup( $text, $byte_offset );
}

/**
 * Back-compat wrapper for generated anchor markup.
 */
function auto_linker_build_anchor_html( string $label, string $url, string $color = '', string $bg_color = '', bool $bold = false ): string {
	return Autolink_Text_Bot::build_anchor_html( $label, $url, $color, $bg_color, $bold );
}

/**
 * Back-compat wrapper for generated opening anchor markup.
 */
function auto_linker_build_opening_anchor_html( string $url, string $color = '', string $bg_color = '', bool $bold = false ): string {
	return Autolink_Text_Bot::build_opening_anchor_html( $url, $color, $bg_color, $bold );
}

/**
 * Back-compat wrapper for generated link styles.
 */
function auto_linker_build_link_style( string $color, string $bg_color, bool $bold ): string {
	return Autolink_Text_Bot::build_link_style( $color, $bg_color, $bold );
}

/**
 * Back-compat wrapper for applying a YDoc link candidate.
 *
 * @param array{text_type:\Yjs\YNestedText,text:string,path:string,match_mode?:string} $candidate Link candidate.
 * @return array<string,mixed>|null
 */
function auto_linker_ydoc_apply_link_candidate( int $post_id, string $room, \Yjs\YDoc $doc, WP_User $bot_user, array $candidate, array &$state ): ?array {
	return Autolink_Text_Bot::apply_link_candidate( $post_id, $room, $doc, $bot_user, $candidate, $state );
}

/**
 * Back-compat wrapper for appending bot updates.
 *
 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Response.
 * @param array<string, mixed>                             $bot_update Bot update metadata.
 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|mixed
 */
function auto_linker_append_bot_update_to_response( $response, string $room, array $bot_update ) {
	return Autolink_Text_Bot::append_bot_update_to_response( $response, $room, $bot_update );
}
