<?php
/**
 * Plugin Name: Auto Linker
 * Description: Automatically links configured terms in completed Gutenberg paragraphs through a PHP-only Gutenberg RTC bot.
 * Version: 0.1.0
 * Requires Plugins: gutenberg
 * Author: Alex Kirk
 * Text Domain: auto-linker
 *
 * @package Auto_Linker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const AUTO_LINKER_OPTION_BOT_USER_ID = 'auto_linker_bot_user_id';
const AUTO_LINKER_OPTION_TERMS = 'auto_linker_terms';
const AUTO_LINKER_BOT_CLOCK_META_KEY = '_auto_linker_bot_clock';
const AUTO_LINKER_ROOM_STATE_META_KEY = '_auto_linker_room_state';
const AUTO_LINKER_AWARENESS_NUDGE_TTL = 20;
const AUTO_LINKER_ROOM_STATE_SCHEMA_VERSION = 3;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/gutenberg-yjs-update-v2.php';
require_once __DIR__ . '/includes/gutenberg-rtc-paragraphs.php';
require_once __DIR__ . '/includes/gutenberg-rtc-debug-log.php';

add_action( 'admin_init', 'auto_linker_register_settings' );
add_action( 'admin_menu', 'auto_linker_register_settings_page' );
add_filter( 'rest_pre_dispatch', 'auto_linker_log_wp_sync_requests', 10, 3 );
add_filter( 'rest_post_dispatch', 'auto_linker_respond_to_wp_sync_requests', 10, 3 );

/**
 * Registers Auto Linker settings.
 */
function auto_linker_register_settings(): void {
	register_setting(
		'auto_linker',
		AUTO_LINKER_OPTION_BOT_USER_ID,
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		)
	);

	register_setting(
		'auto_linker',
		AUTO_LINKER_OPTION_TERMS,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'auto_linker_sanitize_terms',
			'default'           => auto_linker_default_terms(),
		)
	);

	add_settings_section(
		'auto_linker_bot_section',
		__( 'Bot identity', 'auto-linker' ),
		'__return_null',
		'auto_linker'
	);

	add_settings_field(
		AUTO_LINKER_OPTION_BOT_USER_ID,
		__( 'Bot user', 'auto-linker' ),
		'auto_linker_render_bot_user_field',
		'auto_linker',
		'auto_linker_bot_section'
	);

	add_settings_section(
		'auto_linker_terms_section',
		__( 'Terms', 'auto-linker' ),
		'__return_null',
		'auto_linker'
	);

	add_settings_field(
		AUTO_LINKER_OPTION_TERMS,
		__( 'Linked terms', 'auto-linker' ),
		'auto_linker_render_terms_field',
		'auto_linker',
		'auto_linker_terms_section'
	);
}

/**
 * Registers the settings page.
 */
function auto_linker_register_settings_page(): void {
	add_options_page(
		__( 'Auto Linker', 'auto-linker' ),
		__( 'Auto Linker', 'auto-linker' ),
		'manage_options',
		'auto-linker',
		'auto_linker_render_settings_page'
	);
}

/**
 * Renders the bot user setting.
 */
function auto_linker_render_bot_user_field(): void {
	wp_dropdown_users(
		array(
			'name'              => AUTO_LINKER_OPTION_BOT_USER_ID,
			'id'                => AUTO_LINKER_OPTION_BOT_USER_ID,
			'selected'          => auto_linker_get_bot_user_id(),
			'show_option_none'  => __( 'Select a user', 'auto-linker' ),
			'option_none_value' => 0,
			'role__in'          => array( 'administrator', 'editor', 'author', 'contributor' ),
		)
	);
}

/**
 * Renders the linked terms setting.
 */
function auto_linker_render_terms_field(): void {
	$terms = auto_linker_get_terms();
	$terms[] = array(
		'term' => '',
		'url'  => '',
	);
	?>
	<table class="widefat striped" style="max-width: 900px;">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Term', 'auto-linker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'URL', 'auto-linker' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $terms as $index => $term ) : ?>
				<tr>
					<td>
						<input
							type="text"
							name="<?php echo esc_attr( AUTO_LINKER_OPTION_TERMS ); ?>[<?php echo esc_attr( (string) $index ); ?>][term]"
							value="<?php echo esc_attr( $term['term'] ); ?>"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'Playground', 'auto-linker' ); ?>"
						/>
					</td>
					<td>
						<input
							type="url"
							name="<?php echo esc_attr( AUTO_LINKER_OPTION_TERMS ); ?>[<?php echo esc_attr( (string) $index ); ?>][url]"
							value="<?php echo esc_attr( $term['url'] ); ?>"
							class="large-text"
							placeholder="<?php esc_attr_e( 'https://playground.wordpress.net/', 'auto-linker' ); ?>"
						/>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'Leave a row blank to ignore it. Save once to add another empty row.', 'auto-linker' ); ?></p>
	<?php
}

/**
 * Renders the settings page.
 */
function auto_linker_render_settings_page(): void {
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p><?php esc_html_e( 'Choose the WordPress user Auto Linker should use when emitting PHP-generated Gutenberg RTC updates, then configure the terms it should link.', 'auto-linker' ); ?></p>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'auto_linker' );
			do_settings_sections( 'auto_linker' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Gets the configured bot user ID.
 */
function auto_linker_get_bot_user_id(): int {
	return absint( get_option( AUTO_LINKER_OPTION_BOT_USER_ID, 0 ) );
}

/**
 * Gets the configured terms.
 *
 * @return array<int,array{term:string,url:string}>
 */
function auto_linker_get_terms(): array {
	return auto_linker_sanitize_terms( get_option( AUTO_LINKER_OPTION_TERMS, auto_linker_default_terms() ) );
}

/**
 * Gets the default linked terms.
 *
 * @return array<int,array{term:string,url:string}>
 */
function auto_linker_default_terms(): array {
	return array(
		array(
			'term' => 'Playground',
			'url'  => 'https://playground.wordpress.net/',
		),
	);
}

/**
 * Sanitizes term settings.
 *
 * @param mixed $value Posted option value.
 * @return array<int,array{term:string,url:string}>
 */
function auto_linker_sanitize_terms( $value ): array {
	$rows = is_string( $value ) ? auto_linker_parse_terms_text( $value ) : $value;
	if ( ! is_array( $rows ) ) {
		return auto_linker_default_terms();
	}

	$terms = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$term = isset( $row['term'] ) ? sanitize_text_field( (string) $row['term'] ) : '';
		$url  = isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '';
		if ( '' === $term || '' === $url ) {
			continue;
		}

		$terms[] = array(
			'term' => $term,
			'url'  => $url,
		);
	}

	return $terms ?: auto_linker_default_terms();
}

/**
 * Parses legacy text rows into term records.
 *
 * @return array<int,array{term:string,url:string}>
 */
function auto_linker_parse_terms_text( string $text ): array {
	$rows = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: array() as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}

		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		if ( 2 !== count( $parts ) ) {
			continue;
		}

		$rows[] = array(
			'term' => $parts[0],
			'url'  => $parts[1],
		);
	}

	return $rows;
}

/**
 * Gets the stable RTC client ID used by Auto Linker for a bot user.
 */
function auto_linker_get_bot_client_id( int $bot_user_id ): int {
	return abs( crc32( 'auto-linker-bot-' . $bot_user_id ) );
}

/**
 * Emits the configured bot user's awareness state into the sync room.
 *
 * @return true|WP_Error
 */
function auto_linker_emit_bot_awareness( int $post_id, string $room, string $changed_text ) {
	if ( ! $post_id || '' === $room ) {
		return new WP_Error( 'auto_linker_missing_room', __( 'Missing Auto Linker room.', 'auto-linker' ) );
	}

	$bot_user_id = auto_linker_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return new WP_Error( 'auto_linker_missing_bot_user', __( 'No Auto Linker bot user is configured.', 'auto-linker' ) );
	}

	$bot_user = get_user_by( 'id', $bot_user_id );
	if ( ! $bot_user || ! user_can( $bot_user, 'edit_post', $post_id ) ) {
		return new WP_Error( 'auto_linker_bot_cannot_edit', __( 'The configured Auto Linker bot user cannot edit this post.', 'auto-linker' ) );
	}

		$previous_user_id = get_current_user_id();
		wp_set_current_user( $bot_user_id );

		$request = new WP_REST_Request( 'POST', '/wp-sync/v1/updates' );
	$request->set_body_params(
		array(
			'rooms' => array(
				array(
					'after'     => 0,
					'awareness' => array(
						'collaboratorInfo' => auto_linker_build_collaborator_info( $bot_user ),
						'editorState'      => array(
							'selection' => array(
								'type' => 'none',
							),
						),
						'autoLinkerState'  => array(
							'postId'      => $post_id,
							'changedText' => $changed_text,
						),
					),
					'client_id' => auto_linker_get_bot_client_id( $bot_user_id ),
					'room'      => $room,
					'updates'   => array(),
				),
			),
		)
	);

	$response = rest_do_request( $request );
	wp_set_current_user( $previous_user_id );

	if ( $response->is_error() ) {
		return $response->as_error();
	}

	return true;
}

/**
 * Emits the configured bot user's text selection awareness into the sync room.
 *
 * @return WP_REST_Response|WP_Error
 */
function auto_linker_emit_bot_selection_awareness( int $post_id, string $room, string $changed_text, array $selection ) {
	if ( ! $post_id || '' === $room ) {
		return new WP_Error( 'auto_linker_missing_room', __( 'Missing Auto Linker room.', 'auto-linker' ) );
	}

	$bot_user_id = auto_linker_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return new WP_Error( 'auto_linker_missing_bot_user', __( 'No Auto Linker bot user is configured.', 'auto-linker' ) );
	}

	$bot_user = get_user_by( 'id', $bot_user_id );
	if ( ! $bot_user || ! user_can( $bot_user, 'edit_post', $post_id ) ) {
		return new WP_Error( 'auto_linker_bot_cannot_edit', __( 'The configured Auto Linker bot user cannot edit this post.', 'auto-linker' ) );
	}

	$previous_user_id = get_current_user_id();
	wp_set_current_user( $bot_user_id );

	$request = new WP_REST_Request( 'POST', '/wp-sync/v1/updates' );
	$request->set_body_params(
		array(
			'rooms' => array(
				array(
					'after'     => 0,
					'awareness' => auto_linker_build_bot_selection_awareness(
						$bot_user,
						$post_id,
						$changed_text,
						$selection
					),
					'client_id' => auto_linker_get_bot_client_id( $bot_user_id ),
					'room'      => $room,
					'updates'   => array(),
				),
			),
		)
	);

	$response = rest_do_request( $request );
	wp_set_current_user( $previous_user_id );

	if ( $response->is_error() ) {
		return $response->as_error();
	}

	return $response;
}

/**
 * Emits immediate bot awareness for post rooms in a sync payload.
 *
 * @param array<int, mixed> $rooms Rooms payload.
 */
function auto_linker_maybe_emit_bot_awareness_nudges_for_rooms( array $rooms ): void {
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

		if ( $post_id ) {
			auto_linker_maybe_emit_bot_awareness_nudge( $post_id, $room, $client_id );
		}
	}
}

/**
 * Emits bot awareness for solo post-room polls so Gutenberg resumes its update queue.
 */
function auto_linker_maybe_emit_bot_awareness_nudge( int $post_id, string $room, int $client_id ): void {
	$bot_user_id = auto_linker_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return;
	}

	$bot_client_id = auto_linker_get_bot_client_id( $bot_user_id );
	if ( $client_id === $bot_client_id ) {
		return;
	}

	$transient_key = 'auto_linker_awareness_nudge_' . md5( $room );
	if ( get_transient( $transient_key ) ) {
		return;
	}

	set_transient( $transient_key, time(), AUTO_LINKER_AWARENESS_NUDGE_TTL );

	$result = auto_linker_emit_bot_awareness( $post_id, $room, '' );
	auto_linker_log(
		'bot-rtc-awareness-nudge',
		is_wp_error( $result )
			? array(
				'ok'        => false,
				'room'      => $room,
				'post_id'   => $post_id,
				'client_id' => $client_id,
				'code'      => $result->get_error_code(),
				'message'   => $result->get_error_message(),
			)
			: array(
				'ok'            => true,
				'room'          => $room,
				'post_id'       => $post_id,
				'client_id'     => $client_id,
				'bot_client_id' => $bot_client_id,
			)
	);
}

/**
 * Passively logs real Gutenberg sync requests without intercepting them.
 *
 * @param mixed           $result  Response to replace requested version with.
 * @param WP_REST_Server  $server  Server instance.
 * @param WP_REST_Request $request Request used to generate the response.
 * @return mixed Unchanged response.
 */
function auto_linker_log_wp_sync_requests( $result, WP_REST_Server $server, WP_REST_Request $request ) {
	unset( $server );

	if ( '/wp-sync/v1/updates' !== $request->get_route() || 'POST' !== $request->get_method() ) {
		return $result;
	}

	$rooms = \Auto_Linker\Gutenberg_RTC\gutenberg_rtc_get_request_rooms( $request );
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
			'rooms'        => \Auto_Linker\Gutenberg_RTC\gutenberg_rtc_summarize_rooms( $rooms ),
		)
	);

	auto_linker_maybe_emit_bot_awareness_nudges_for_rooms( $rooms );
	\Auto_Linker\Gutenberg_RTC\gutenberg_rtc_decode_rooms_for_logging( $rooms, 'auto_linker_log' );

	return $result;
}

/**
 * Responds to accepted Gutenberg sync updates with PHP-generated bot RTC updates.
 *
 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Response.
 * @param WP_REST_Server                                   $server   Server instance.
 * @param WP_REST_Request                                  $request  Request.
 * @return mixed Unchanged response.
 */
function auto_linker_respond_to_wp_sync_requests( $response, WP_REST_Server $server, WP_REST_Request $request ) {
	unset( $server );

	if ( '/wp-sync/v1/updates' !== $request->get_route() || 'POST' !== $request->get_method() ) {
		return $response;
	}

	$rooms = \Auto_Linker\Gutenberg_RTC\gutenberg_rtc_get_request_rooms( $request );
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

		$state      = auto_linker_get_room_state( $post_id );
		$paragraphs = array();
		if ( ! empty( $updates ) ) {
			$paragraphs = \Auto_Linker\Gutenberg_RTC\gutenberg_rtc_apply_paragraph_updates(
				$state,
				$updates,
				static function ( RuntimeException $exception ) use ( $room ): void {
					auto_linker_log(
						'bot-rtc-decode-error',
						array(
							'room'    => $room,
							'message' => $exception->getMessage(),
						)
					);
				}
			);
		}

		auto_linker_set_room_state( $post_id, $state );
		foreach ( auto_linker_emit_pending_links( $post_id, $room, $state ) as $bot_update ) {
			$response = auto_linker_append_bot_update_to_response( $response, $room, $bot_update );
		}

		foreach ( auto_linker_link_completed_paragraphs( $post_id, $room, $state, $paragraphs ) as $bot_update ) {
			$response = auto_linker_append_bot_update_to_response( $response, $room, $bot_update );
		}
	}

	return $response;
}

/**
 * Adds a bot-authored update to the current sync response so the active editor receives it immediately.
 *
 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Response.
 * @param array<string, mixed>                             $bot_update Bot update metadata.
 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|mixed
 */
function auto_linker_append_bot_update_to_response( $response, string $room, array $bot_update ) {
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

/**
 * Auto-links matching terms in linkable paragraph text events.
 *
 * @param array<string, mixed>                          $state      Current paragraph document state.
 * @param array<int, \Auto_Linker\Gutenberg_RTC\Gutenberg_RTC_Completed_Paragraph> $paragraphs Linkable paragraph text events.
 */
function auto_linker_link_completed_paragraphs( int $post_id, string $room, array &$state, array $paragraphs ): array {
	$bot_updates = array();

	foreach ( $paragraphs as $paragraph ) {
		if ( ! $paragraph instanceof \Auto_Linker\Gutenberg_RTC\Gutenberg_RTC_Completed_Paragraph ) {
			continue;
		}

		if ( auto_linker_has_pending_link_for_block( $state, $paragraph->source_block_id() ) ) {
			auto_linker_log(
				'bot-rtc-auto-link-skip',
				array(
					'room'     => $room,
					'block_id' => $paragraph->source_block_id(),
					'reason'   => 'pending_link_exists',
					'text'     => $paragraph->text(),
				)
			);
			continue;
		}

		$dedupe_key = $paragraph->dedupe_key();
		if ( isset( $state['processed'][ $dedupe_key ] ) ) {
			continue;
		}

		$state['processed'][ $dedupe_key ] = time();
		auto_linker_set_room_state( $post_id, $state );

		$terms = auto_linker_get_terms();
		auto_linker_log(
			'bot-rtc-auto-link-candidate',
			array(
				'room'       => $room,
				'block_id'   => $paragraph->source_block_id(),
				'text'       => $paragraph->text(),
				'term_count' => count( $terms ),
				'terms'      => array_map(
					static fn( array $term ): string => (string) ( $term['term'] ?? '' ),
					$terms
				),
			)
		);

		$match = auto_linker_find_first_unlinked_term( $paragraph->text(), $terms );
		if ( ! $match ) {
			auto_linker_log(
				'bot-rtc-auto-link-skip',
				array(
					'room'   => $room,
					'reason' => 'no_matching_term',
					'text'   => $paragraph->text(),
				)
			);
			continue;
		}

		$result = auto_linker_queue_bot_term_link( $post_id, $room, $state, $dedupe_key, $match, $paragraph );
		auto_linker_log(
			'bot-rtc-auto-link-highlight',
			is_wp_error( $result )
				? array(
					'ok'      => false,
					'room'    => $room,
					'term'    => $match['term'],
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
				: array_merge( array( 'room' => $room ), $result )
		);

		if ( ! is_wp_error( $result ) && is_array( $result ) ) {
			break;
		}
	}

	return $bot_updates;
}

/**
 * Emits bot selection awareness and queues the actual link mutation for a later sync turn.
 *
 * @param array<string, mixed> $state State, mutated in place.
 * @param array{term:string,url:string,matched_text:string,start:int,length:int,replacement:string,opening_text:string,closing_text:string} $match Match metadata.
 * @return array<string, mixed>|WP_Error
 */
function auto_linker_queue_bot_term_link( int $post_id, string $room, array &$state, string $dedupe_key, array $match, \Auto_Linker\Gutenberg_RTC\Gutenberg_RTC_Completed_Paragraph $paragraph ) {
	$bot_user_id = auto_linker_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return new WP_Error( 'auto_linker_missing_bot_user', __( 'No Auto Linker bot user is configured.', 'auto-linker' ) );
	}

	$bot_user = get_user_by( 'id', $bot_user_id );
	if ( ! $bot_user || ! user_can( $bot_user, 'edit_post', $post_id ) ) {
		return new WP_Error( 'auto_linker_bot_cannot_edit', __( 'The configured Auto Linker bot user cannot edit this post.', 'auto-linker' ) );
	}

	$link_update = \Auto_Linker\Gutenberg_RTC\gutenberg_rtc_build_text_wrap(
		$state,
		$paragraph,
		(int) $match['start'],
		(int) $match['length'],
		(string) $match['opening_text'],
		(string) $match['closing_text'],
		auto_linker_get_bot_client_id( $bot_user_id ),
		auto_linker_get_bot_clock( $post_id, auto_linker_get_bot_client_id( $bot_user_id ) )
	);
	if ( ! $link_update ) {
		return new WP_Error( 'auto_linker_no_selection', __( 'Could not build a term selection for this paragraph.', 'auto-linker' ) );
	}

	$result = auto_linker_emit_bot_selection_awareness(
		$post_id,
		$room,
		(string) $match['replacement'],
		$link_update['selection']
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! isset( $state['pending_links'] ) || ! is_array( $state['pending_links'] ) ) {
		$state['pending_links'] = array();
	}

	$state['pending_links'][ $dedupe_key ] = array(
		'block_id'       => $paragraph->source_block_id(),
		'term'           => (string) $match['term'],
		'url'            => (string) $match['url'],
		'queued_at'      => time(),
		'attempts'       => 0,
		'awaiting_fetch' => true,
	);
	auto_linker_set_room_state( $post_id, $state );

	return array(
		'ok'              => true,
		'queued'          => true,
		'room'            => $room,
		'block_id'        => $paragraph->source_block_id(),
		'term'            => $match['term'],
		'matched_text'    => $match['matched_text'],
		'replacement'     => $match['replacement'],
		'selection'       => $link_update['selection'],
		'response_status' => $result->get_status(),
	);
}

/**
 * Checks whether a block already has a queued link mutation.
 *
 * @param array<string, mixed> $state State.
 */
function auto_linker_has_pending_link_for_block( array $state, string $block_id ): bool {
	if ( '' === $block_id || empty( $state['pending_links'] ) || ! is_array( $state['pending_links'] ) ) {
		return false;
	}

	foreach ( $state['pending_links'] as $pending_link ) {
		if ( is_array( $pending_link ) && $block_id === (string) ( $pending_link['block_id'] ?? '' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Removes all queued link mutations for a block.
 *
 * @param array<string, mixed> $state State, mutated in place.
 */
function auto_linker_remove_pending_links_for_block( array &$state, string $block_id ): bool {
	if ( '' === $block_id || empty( $state['pending_links'] ) || ! is_array( $state['pending_links'] ) ) {
		return false;
	}

	$removed = false;
	foreach ( array_keys( $state['pending_links'] ) as $pending_key ) {
		$pending_link = $state['pending_links'][ $pending_key ];
		if ( is_array( $pending_link ) && $block_id === (string) ( $pending_link['block_id'] ?? '' ) ) {
			unset( $state['pending_links'][ $pending_key ] );
			$removed = true;
		}
	}

	return $removed;
}

/**
 * Emits one queued bot link mutation after its selection awareness has had a sync turn to render.
 *
 * @param array<string, mixed> $state State, mutated in place.
 * @return array<int,array<string,mixed>>
 */
function auto_linker_emit_pending_links( int $post_id, string $room, array &$state ): array {
	if ( empty( $state['pending_links'] ) || ! is_array( $state['pending_links'] ) ) {
		return array();
	}

	auto_linker_log(
		'bot-rtc-pending-link-check',
		array(
			'room'          => $room,
			'post_id'       => $post_id,
			'pending_count' => count( $state['pending_links'] ),
			'pending_keys'  => array_keys( $state['pending_links'] ),
		)
	);

	$bot_updates = array();
	$changed     = false;
	foreach ( $state['pending_links'] as $pending_key => $pending_link ) {
		if ( ! is_array( $pending_link ) ) {
			auto_linker_log(
				'bot-rtc-pending-link-drop',
				array(
					'room'   => $room,
					'key'    => (string) $pending_key,
					'reason' => 'pending_link_must_be_object',
				)
			);
			unset( $state['pending_links'][ $pending_key ] );
			$changed = true;
			continue;
		}

		$block_id = isset( $pending_link['block_id'] ) ? (string) $pending_link['block_id'] : '';
		$block    = $block_id && isset( $state['blocks'][ $block_id ] ) && is_array( $state['blocks'][ $block_id ] ) ? $state['blocks'][ $block_id ] : null;
		$text     = is_array( $block ) ? (string) ( $block['content'] ?? '' ) : '';
		$block_yid = is_array( $block ) && isset( $block['id'] ) && is_array( $block['id'] ) ? $block['id'] : null;

		if ( '' === $block_id || '' === $text || ! $block_yid ) {
			auto_linker_log(
				'bot-rtc-pending-link-drop',
				array(
					'room'     => $room,
					'key'      => (string) $pending_key,
					'block_id' => $block_id,
					'reason'   => '' === $block_id ? 'missing_block_id' : ( '' === $text ? 'empty_block_text' : 'missing_block_yjs_id' ),
				)
			);
			unset( $state['pending_links'][ $pending_key ] );
			$changed = true;
			continue;
		}

		if ( ! empty( $pending_link['awaiting_fetch'] ) ) {
			auto_linker_log(
				'bot-rtc-pending-link-wait',
				array(
					'room'     => $room,
					'key'      => (string) $pending_key,
					'block_id' => $block_id,
					'text'     => $text,
					'reason'   => 'awaiting_highlight_fetch',
				)
			);
			$state['pending_links'][ $pending_key ]['awaiting_fetch'] = false;
			auto_linker_set_room_state( $post_id, $state );
			break;
		}

		$match = auto_linker_find_first_unlinked_term( $text, auto_linker_get_terms() );
		if ( ! $match ) {
			$attempts = (int) ( $pending_link['attempts'] ?? 0 ) + 1;
			if ( $attempts < 5 && ! auto_linker_block_appears_linked( $text, (string) ( $pending_link['url'] ?? '' ) ) ) {
				auto_linker_log(
					'bot-rtc-pending-link-wait',
					array(
						'room'     => $room,
						'key'      => (string) $pending_key,
						'block_id' => $block_id,
						'reason'   => 'no_matching_unlinked_term_retry',
						'attempts' => $attempts,
						'text'     => $text,
					)
				);
				$state['pending_links'][ $pending_key ]['attempts'] = $attempts;
				auto_linker_set_room_state( $post_id, $state );
				break;
			}

			auto_linker_log(
				'bot-rtc-pending-link-drop',
				array(
					'room'     => $room,
					'key'      => (string) $pending_key,
					'block_id' => $block_id,
					'reason'   => 'no_matching_unlinked_term',
					'attempts' => $attempts,
					'text'     => $text,
				)
			);
			auto_linker_remove_pending_links_for_block( $state, $block_id );
			$changed = true;
			continue;
		}

		$paragraph = new \Auto_Linker\Gutenberg_RTC\Gutenberg_RTC_Completed_Paragraph(
			$block_id,
			$text,
			$block_yid,
			null
		);
		auto_linker_log(
			'bot-rtc-pending-link-emit',
			array(
				'room'         => $room,
				'key'          => (string) $pending_key,
				'block_id'     => $block_id,
				'term'         => $match['term'],
				'matched_text' => $match['matched_text'],
				'start'        => $match['start'],
				'length'       => $match['length'],
				'text'         => $text,
			)
		);
		$result = auto_linker_emit_bot_term_link( $post_id, $room, $match, $paragraph );
		auto_linker_log(
			'bot-rtc-auto-link',
			is_wp_error( $result )
				? array(
					'ok'      => false,
					'room'    => $room,
					'term'    => $match['term'],
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
				: array_merge( array( 'room' => $room ), $result )
		);

		auto_linker_remove_pending_links_for_block( $state, $block_id );
		$changed = true;
		if ( ! is_wp_error( $result ) && is_array( $result ) ) {
			$latest_state = auto_linker_get_room_state( $post_id );
			auto_linker_remove_pending_links_for_block( $latest_state, $block_id );
			$state = $latest_state;
			auto_linker_set_room_state( $post_id, $state );
			$bot_updates[] = $result;
		} else {
			auto_linker_set_room_state( $post_id, $state );
		}

		break;
	}

	if ( $changed ) {
		auto_linker_set_room_state( $post_id, $state );
	}

	return $bot_updates;
}

/**
 * Checks whether a pending term has already landed as a link in the block text.
 */
function auto_linker_block_appears_linked( string $text, string $url ): bool {
	if ( '' === $url ) {
		return false;
	}

	return false !== stripos( $text, '<a ' ) && false !== strpos( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $url );
}

/**
 * Finds the first configured term that is not already inside markup.
 *
 * @param array<int,array{term:string,url:string}> $terms Terms.
 * @return array{term:string,url:string,matched_text:string,start:int,length:int,replacement:string}|null
 */
function auto_linker_find_first_unlinked_term( string $text, array $terms ): ?array {
	if ( ! auto_linker_has_balanced_anchor_markup( $text ) ) {
		return null;
	}

	foreach ( $terms as $term ) {
		$label = (string) ( $term['term'] ?? '' );
		$url   = (string) ( $term['url'] ?? '' );
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
			if ( auto_linker_offset_is_inside_markup( $text, $byte_offset ) ) {
				continue;
			}

			return array(
				'term'         => $label,
				'url'          => $url,
				'matched_text' => $matched_text,
				'start'        => \Auto_Linker\Gutenberg_RTC\gutenberg_yjs_utf16_clock_len( substr( $text, 0, $byte_offset ) ),
				'length'       => \Auto_Linker\Gutenberg_RTC\gutenberg_yjs_utf16_clock_len( $matched_text ),
				'replacement'  => auto_linker_build_anchor_html( $matched_text, $url ),
				'opening_text' => auto_linker_build_opening_anchor_html( $url ),
				'closing_text' => '</a>',
			);
		}
	}

	return null;
}

/**
 * Checks whether reconstructed text has balanced anchor tags.
 */
function auto_linker_has_balanced_anchor_markup( string $text ): bool {
	return substr_count( strtolower( $text ), '<a ' ) === substr_count( strtolower( $text ), '</a>' );
}

/**
 * Checks whether a byte offset is inside an HTML tag or anchor.
 */
function auto_linker_offset_is_inside_markup( string $text, int $byte_offset ): bool {
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
function auto_linker_build_anchor_html( string $label, string $url ): string {
	return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
}

/**
 * Builds the opening anchor tag inserted before linked text.
 */
function auto_linker_build_opening_anchor_html( string $url ): string {
	return '<a href="' . esc_url( $url ) . '">';
}

/**
 * Emits a bot-authored term replacement into a Gutenberg sync room.
 *
 * @param array{term:string,url:string,matched_text:string,start:int,length:int,replacement:string,opening_text:string,closing_text:string} $match Match metadata.
 * @return array<string, mixed>|WP_Error
 */
function auto_linker_emit_bot_term_link( int $post_id, string $room, array $match, \Auto_Linker\Gutenberg_RTC\Gutenberg_RTC_Completed_Paragraph $paragraph ) {
	if ( ! $post_id || '' === $room ) {
		return new WP_Error( 'auto_linker_missing_room', __( 'Missing Auto Linker room.', 'auto-linker' ) );
	}

	$bot_user_id = auto_linker_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return new WP_Error( 'auto_linker_missing_bot_user', __( 'No Auto Linker bot user is configured.', 'auto-linker' ) );
	}

	$bot_user = get_user_by( 'id', $bot_user_id );
	if ( ! $bot_user || ! user_can( $bot_user, 'edit_post', $post_id ) ) {
		return new WP_Error( 'auto_linker_bot_cannot_edit', __( 'The configured Auto Linker bot user cannot edit this post.', 'auto-linker' ) );
	}

	$bot_client_id = auto_linker_get_bot_client_id( $bot_user_id );
	$start_clock   = auto_linker_get_bot_clock( $post_id, $bot_client_id );
	$state         = auto_linker_get_room_state( $post_id );
	$link_update   = \Auto_Linker\Gutenberg_RTC\gutenberg_rtc_build_text_wrap(
		$state,
		$paragraph,
		(int) $match['start'],
		(int) $match['length'],
		(string) $match['opening_text'],
		(string) $match['closing_text'],
		$bot_client_id,
		$start_clock
	);
	if ( ! $link_update ) {
		return new WP_Error( 'auto_linker_no_replacement', __( 'Could not build a term link for this paragraph.', 'auto-linker' ) );
	}

	$update           = $link_update['update'];
	$update_data      = base64_encode( $update );
	$previous_user_id = get_current_user_id();
	wp_set_current_user( $bot_user_id );

	$request = new WP_REST_Request( 'POST', '/wp-sync/v1/updates' );
	$request->set_body_params(
		array(
			'rooms' => array(
				array(
					'after'     => 0,
					'awareness' => auto_linker_build_bot_selection_awareness(
						$bot_user,
						$post_id,
						(string) $match['replacement'],
						$link_update['selection']
					),
					'client_id' => $bot_client_id,
					'room'      => $room,
					'updates'   => array(
						array(
							'type' => 'update',
							'data' => $update_data,
						),
					),
				),
			),
		)
	);

	$response = rest_do_request( $request );
	wp_set_current_user( $previous_user_id );

	if ( $response->is_error() ) {
		return $response->as_error();
	}

	try {
		$decoded = \Auto_Linker\Gutenberg_RTC\gutenberg_yjs_decode_update_v2( $update );
		\Auto_Linker\Gutenberg_RTC\gutenberg_rtc_apply_decoded_update_to_paragraph_state( $state, $decoded );
		$state['blocks'][ $paragraph->source_block_id() ]['content'] = auto_linker_replace_first_match( $paragraph->text(), $match );
		auto_linker_set_room_state( $post_id, $state );
	} catch ( RuntimeException $exception ) {
		auto_linker_log(
			'bot-rtc-state-apply-error',
			array(
				'room'    => $room,
				'message' => $exception->getMessage(),
			)
		);
	}

	auto_linker_set_bot_clock( $post_id, $bot_client_id, (int) $link_update['next_clock'] );

	return array(
		'ok'               => true,
		'bot_client_id'    => $bot_client_id,
		'start_clock'      => $start_clock,
		'next_clock'       => (int) $link_update['next_clock'],
		'update_bytes'     => strlen( $update ),
		'update_data'      => $update_data,
		'term'             => $match['term'],
		'url'              => $match['url'],
		'matched_text'     => $match['matched_text'],
		'replacement'      => $match['replacement'],
		'opening_text'     => $match['opening_text'],
		'closing_text'     => $match['closing_text'],
		'selection'        => $link_update['selection'],
		'open_origin'      => $link_update['open_origin'],
		'open_right'       => $link_update['open_right'],
		'close_origin'     => $link_update['close_origin'],
		'close_right'      => $link_update['close_right'],
		'delete_ranges'    => $link_update['delete_ranges'],
		'response_status'  => $response->get_status(),
		'response_payload' => $response->get_data(),
	);
}

/**
 * Replaces the first matched term in local text state.
 *
 * @param array{term:string,url:string,matched_text:string,start:int,length:int,replacement:string} $match Match metadata.
 */
function auto_linker_replace_first_match( string $text, array $match ): string {
	$pattern = '/(?<![\p{L}\p{N}_])' . preg_quote( (string) $match['matched_text'], '/' ) . '(?=[^\p{L}\p{N}_])/u';
	return preg_replace_callback(
		$pattern,
		static fn(): string => (string) $match['replacement'],
		$text,
		1
	) ?? $text;
}

/**
 * Builds bot awareness for a text selection.
 *
 * @param array<string, mixed> $selection Selection metadata from the RTC builder.
 */
function auto_linker_build_bot_selection_awareness( WP_User $bot_user, int $post_id, string $changed_text, array $selection ): array {
	$type         = isset( $selection['type'] ) && is_array( $selection['type'] ) ? $selection['type'] : array();
	$start_item   = isset( $selection['start_item'] ) && is_array( $selection['start_item'] ) ? $selection['start_item'] : null;
	$end_item     = isset( $selection['end_item'] ) && is_array( $selection['end_item'] ) ? $selection['end_item'] : null;
	$start_offset = isset( $selection['start_offset'] ) ? (int) $selection['start_offset'] : 0;
	$end_offset   = isset( $selection['end_offset'] ) ? (int) $selection['end_offset'] : $start_offset;

	return array(
		'collaboratorInfo' => auto_linker_build_collaborator_info( $bot_user ),
		'editorState'      => array(
			'selection' => array(
				'type'                => 'selection-in-one-block',
				'cursorStartPosition' => auto_linker_build_bot_cursor_position( $type, $start_item, $start_offset ),
				'cursorEndPosition'   => auto_linker_build_bot_cursor_position( $type, $end_item, $end_offset ),
				'selectionDirection'  => 'f',
			),
		),
		'autoLinkerState'  => array(
			'postId'      => $post_id,
			'changedText' => $changed_text,
		),
	);
}

/**
 * Builds collaborator metadata for bot awareness.
 */
function auto_linker_build_collaborator_info( WP_User $bot_user ): array {
	return array(
		'avatar_urls' => rest_get_avatar_urls( $bot_user->user_email ),
		'browserType' => 'Auto Linker',
		'enteredAt'   => (int) floor( microtime( true ) * 1000 ),
		'id'          => $bot_user->ID,
		'name'        => $bot_user->display_name,
		'slug'        => $bot_user->user_nicename,
	);
}

/**
 * Builds a Gutenberg cursor position payload from Yjs IDs.
 */
function auto_linker_build_bot_cursor_position( array $type, ?array $item, int $absolute_offset ): array {
	return array(
		'relativePosition' => array(
			'type'  => array(
				'client' => (int) ( $type['client'] ?? 0 ),
				'clock'  => (int) ( $type['clock'] ?? 0 ),
			),
			'tname' => null,
			'item'  => $item
				? array(
					'client' => (int) ( $item['client'] ?? 0 ),
					'clock'  => (int) ( $item['clock'] ?? 0 ),
				)
				: null,
			'assoc' => 0,
		),
		'absoluteOffset'   => $absolute_offset,
		'attributeKey'     => 'content',
	);
}

/**
 * Gets the next bot client clock for a post.
 */
function auto_linker_get_bot_clock( int $post_id, int $bot_client_id ): int {
	$clocks = get_post_meta( $post_id, AUTO_LINKER_BOT_CLOCK_META_KEY, true );
	if ( ! is_array( $clocks ) ) {
		return 0;
	}

	return isset( $clocks[ (string) $bot_client_id ] ) ? max( 0, (int) $clocks[ (string) $bot_client_id ] ) : 0;
}

/**
 * Stores the next bot client clock for a post.
 */
function auto_linker_set_bot_clock( int $post_id, int $bot_client_id, int $clock ): void {
	$clocks = get_post_meta( $post_id, AUTO_LINKER_BOT_CLOCK_META_KEY, true );
	if ( ! is_array( $clocks ) ) {
		$clocks = array();
	}

	$clocks[ (string) $bot_client_id ] = max( 0, $clock );
	update_post_meta( $post_id, AUTO_LINKER_BOT_CLOCK_META_KEY, $clocks );
}

/**
 * Extracts a post ID from a Gutenberg post sync room.
 */
function auto_linker_get_post_id_from_room( string $room ): int {
	if ( preg_match( '/^postType\/post:(\d+)$/', $room, $matches ) ) {
		return (int) $matches[1];
	}

	return 0;
}

/**
 * Gets Auto Linker's lightweight CRDT room state.
 *
 * @return array<string, mixed>
 */
function auto_linker_get_room_state( int $post_id ): array {
	$state = get_post_meta( $post_id, AUTO_LINKER_ROOM_STATE_META_KEY, true );
	if ( ! is_array( $state ) ) {
		$state = array();
	}

	if ( (int) ( $state['schema_version'] ?? 0 ) !== AUTO_LINKER_ROOM_STATE_SCHEMA_VERSION ) {
		$state = array();
	}

	return array_merge(
		\Auto_Linker\Gutenberg_RTC\gutenberg_rtc_empty_paragraph_document_state( AUTO_LINKER_ROOM_STATE_SCHEMA_VERSION ),
		$state
	);
}

/**
 * Stores Auto Linker's lightweight CRDT room state.
 */
function auto_linker_set_room_state( int $post_id, array $state ): void {
	update_post_meta( $post_id, AUTO_LINKER_ROOM_STATE_META_KEY, $state );
}

/**
 * Logs a probe event.
 *
 * @param string $event Event name.
 * @param mixed  $data  Event payload.
 */
function auto_linker_log( string $event, $data ): void {
	$message = '[Auto Linker] ' . gmdate( 'c' ) . ' ' . $event . ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
