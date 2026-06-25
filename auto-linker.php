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
const AUTO_LINKER_ROOM_STATE_META_KEY = '_auto_linker_room_state';
const AUTO_LINKER_ROOM_STATE_SCHEMA_VERSION = 9;
const AUTO_LINKER_MAX_YDOC_STATE_BYTES = 524288;
const AUTO_LINKER_MAX_YDOC_STATE_BASE64_LENGTH = 699052;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/gutenberg-yjs-update-v2.php';
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
					'awareness' => auto_linker_build_bot_awareness_state( $bot_user, $post_id, $changed_text ),
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

	$state = auto_linker_get_room_state( $post_id );
	if ( time() < (int) ( $state['selection_until'] ?? 0 ) ) {
		auto_linker_log(
			'bot-rtc-awareness-nudge-skip',
			array(
				'room'          => $room,
				'post_id'       => $post_id,
				'client_id'     => $client_id,
				'bot_client_id' => $bot_client_id,
				'reason'        => 'post_link_selection_active',
			)
		);
		return;
	}

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

		$updates_to_apply = array_merge(
			auto_linker_response_updates_for_room( $response, $room ),
			$updates
		);

		foreach ( auto_linker_ydoc_handle_room_updates( $post_id, $room, $updates_to_apply, $room_request ) as $bot_update ) {
			$response = auto_linker_append_bot_update_to_response( $response, $room, $bot_update );
		}
	}

	return $response;
}

/**
 * Extracts server-returned sync updates for a room from the current REST response.
 *
 * @return array<int,array<string,mixed>>
 */
function auto_linker_response_updates_for_room( $response, string $room ): array {
	if ( ! $response instanceof WP_REST_Response ) {
		return array();
	}

	$data = $response->get_data();
	if ( ! is_array( $data ) || empty( $data['rooms'] ) || ! is_array( $data['rooms'] ) ) {
		return array();
	}

	foreach ( $data['rooms'] as $room_response ) {
		if ( is_array( $room_response ) && (string) ( $room_response['room'] ?? '' ) === $room && isset( $room_response['updates'] ) && is_array( $room_response['updates'] ) ) {
			return $room_response['updates'];
		}
	}

	return array();
}

/**
 * Applies room updates to a real YDoc and emits at most one bot-authored link update.
 *
 * @param array<int,array<string,mixed>> $updates      Incoming sync updates.
 * @param array<string,mixed>            $room_request Current sync room request.
 * @return array<int,array<string,mixed>>
 */
function auto_linker_ydoc_handle_room_updates( int $post_id, string $room, array $updates, array $room_request = array() ): array {
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
	$bot_update  = auto_linker_ydoc_emit_first_link( $post_id, $room, $doc, $state, $room_request );
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
 * Applies one Gutenberg RTC room update entry to a YDoc.
 *
 * @param mixed $update Update entry.
 */
function auto_linker_ydoc_apply_room_update( \Yjs\YDoc $doc, $update, string $room ): void {
	if ( ! is_array( $update ) || empty( $update['data'] ) || ! is_string( $update['data'] ) ) {
		return;
	}

	$type  = isset( $update['type'] ) && is_string( $update['type'] ) ? $update['type'] : '';
	$bytes = base64_decode( $update['data'], true );
	if ( false === $bytes ) {
		auto_linker_log(
			'bot-rtc-ydoc-apply-error',
			array(
				'room'    => $room,
				'type'    => $type,
				'message' => 'Invalid base64 update payload.',
			)
		);
		return;
	}

	try {
		if ( 'update' === $type || 'compaction' === $type ) {
			$doc->applyUpdateV2( $bytes, 'remote' );
			return;
		}

		if ( 'sync_step2' === $type ) {
			\Yjs\Sync\SyncProtocol::applySyncStep2V2( $doc, $bytes, 'remote' );
			return;
		}
	} catch ( Throwable $exception ) {
		auto_linker_log(
			'bot-rtc-ydoc-apply-error',
			array(
				'room'    => $room,
				'type'    => $type,
				'message' => $exception->getMessage(),
			)
		);
	}
}

/**
 * Builds a YDoc from the persisted full-state update.
 */
function auto_linker_ydoc_from_state( int $post_id, array $state ): \Yjs\YDoc {
	$bot_user_id   = auto_linker_get_bot_user_id();
	$bot_client_id = $bot_user_id ? auto_linker_get_bot_client_id( $bot_user_id ) : null;
	$doc           = new \Yjs\YDoc( $bot_client_id ?: null );
	$encoded       = isset( $state['ydoc_update_v2'] ) && is_string( $state['ydoc_update_v2'] ) ? $state['ydoc_update_v2'] : '';

	if ( '' !== $encoded ) {
		if ( strlen( $encoded ) > AUTO_LINKER_MAX_YDOC_STATE_BASE64_LENGTH ) {
			auto_linker_log(
				'bot-rtc-ydoc-state-reset',
				array(
					'post_id'       => $post_id,
					'reason'        => 'persisted_state_base64_too_large',
					'base64_length' => strlen( $encoded ),
				)
			);
			return $doc;
		}

		$bytes = base64_decode( $encoded, true );
		if ( false !== $bytes ) {
			if ( strlen( $bytes ) > AUTO_LINKER_MAX_YDOC_STATE_BYTES ) {
				auto_linker_log(
					'bot-rtc-ydoc-state-reset',
					array(
						'post_id' => $post_id,
						'reason'  => 'persisted_state_too_large',
						'bytes'   => strlen( $bytes ),
					)
				);
				return $doc;
			}

			try {
				$doc->applyUpdateV2( $bytes, 'persisted' );
			} catch ( Throwable $exception ) {
				auto_linker_log(
					'bot-rtc-ydoc-state-error',
					array(
						'post_id' => $post_id,
						'message' => $exception->getMessage(),
					)
				);
				$doc = new \Yjs\YDoc( $bot_client_id ?: null );
			}
		}
	}

	return $doc;
}

/**
 * Fetches a full room snapshot from the sync endpoint when no persisted YDoc state exists.
 *
 * @return array<int,array<string,mixed>>
 */
function auto_linker_fetch_room_snapshot_updates( int $post_id, string $room ): array {
	$bot_user_id = auto_linker_get_bot_user_id();
	if ( ! $bot_user_id || '' === $room ) {
		return array();
	}

	$bot_user = get_user_by( 'id', $bot_user_id );
	if ( ! $bot_user || ! user_can( $bot_user, 'edit_post', $post_id ) ) {
		return array();
	}

	$previous_user_id = get_current_user_id();
	wp_set_current_user( $bot_user_id );

	$request = new WP_REST_Request( 'POST', '/wp-sync/v1/updates' );
	$request->set_body_params(
		array(
			'rooms' => array(
				array(
					'after'     => 0,
					'awareness' => auto_linker_build_bot_awareness_state( $bot_user, $post_id, '' ),
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
		auto_linker_log(
			'bot-rtc-ydoc-bootstrap',
			array(
				'ok'      => false,
				'room'    => $room,
				'code'    => $response->as_error()->get_error_code(),
				'message' => $response->as_error()->get_error_message(),
			)
		);
		return array();
	}

	$updates = auto_linker_response_updates_for_room( $response, $room );
	auto_linker_log(
		'bot-rtc-ydoc-bootstrap',
		array(
			'ok'           => true,
			'room'         => $room,
			'update_count' => count( $updates ),
			'status'       => $response->get_status(),
		)
	);

	return $updates;
}

/**
 * Mutates the first linkable term as the bot, then selects the linked word.
 *
 * @return array<string,mixed>|null
 */
function auto_linker_ydoc_emit_first_link( int $post_id, string $room, \Yjs\YDoc $doc, array &$state, array $room_request = array() ): ?array {
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
	if ( $candidate && ! auto_linker_ydoc_candidate_match( $candidate ) ) {
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
	$match = auto_linker_ydoc_candidate_match( $candidate );
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

	return auto_linker_ydoc_apply_link_candidate( $post_id, $room, $doc, $bot_user, $candidate, $state );
}

/**
 * Applies a link to the current first matching term in a candidate text.
 *
 * @param array{text_type:\Yjs\YNestedText,text:string,path:string,match_mode?:string} $candidate Link candidate.
 * @return array<string,mixed>|null
 */
function auto_linker_ydoc_apply_link_candidate( int $post_id, string $room, \Yjs\YDoc $doc, WP_User $bot_user, array $candidate, array &$state ): ?array {
	$text  = $candidate['text_type']->toString();
	$candidate['text'] = $text;
	$match = auto_linker_ydoc_candidate_match( $candidate );
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
 * Builds a Gutenberg awareness selection for a nested Y.Text range.
 *
 * @return array<string,mixed>|null
 */
function auto_linker_build_ydoc_text_selection( \Yjs\YNestedText $text_type, int $start, int $length, ?int $absolute_start = null, ?int $absolute_end = null ): ?array {
	try {
		$start_position = $text_type->relativePositionAt( $start )->toJSON();
		$end_position   = $text_type->relativePositionAt( $start + $length )->toJSON();
	} catch ( Throwable $exception ) {
		auto_linker_log(
			'bot-rtc-ydoc-selection-error',
			array(
				'type_id' => $text_type->idKey(),
				'start'   => $start,
				'length'  => $length,
				'absolute_start' => $absolute_start,
				'absolute_end'   => $absolute_end,
				'message' => $exception->getMessage(),
			)
		);
		return null;
	}

	$type           = isset( $start_position['type'] ) && is_array( $start_position['type'] )
		? $start_position['type']
		: ( isset( $end_position['type'] ) && is_array( $end_position['type'] ) ? $end_position['type'] : null );

	if ( ! $type ) {
		return null;
	}

	return array(
		'type'         => $type,
		'start_item'   => isset( $start_position['item'] ) && is_array( $start_position['item'] ) ? $start_position['item'] : null,
		'end_item'     => isset( $end_position['item'] ) && is_array( $end_position['item'] ) ? $end_position['item'] : null,
		'start_offset' => $absolute_start ?? $start,
		'end_offset'   => $absolute_end ?? ( $start + $length ),
	);
}

/**
 * Finds the active paragraph Y.Text from the editor awareness cursor.
 *
 * @param array<string,mixed> $room_request Current sync room request.
 * @return array{text_type:\Yjs\YNestedText,text:string,path:string}|null
 */
function auto_linker_ydoc_find_awareness_link_candidate( \Yjs\YDoc $doc, array $room_request ): ?array {
	$type_id = auto_linker_ydoc_awareness_text_type_id( $room_request );
	if ( '' === $type_id ) {
		return null;
	}

	try {
		$text_type = $doc->nestedSharedType( $type_id );
	} catch ( Throwable $exception ) {
		return null;
	}

	if ( ! $text_type instanceof \Yjs\YNestedText ) {
		return null;
	}

	return array(
		'text_type' => $text_type,
		'text'      => $text_type->toString(),
		'path'      => 'awareness:' . $type_id,
	);
}

/**
 * Gets the nested Y.Text ID from a Gutenberg awareness selection.
 *
 * @param array<string,mixed> $room_request Current sync room request.
 */
function auto_linker_ydoc_awareness_text_type_id( array $room_request ): string {
	$selection = $room_request['awareness']['editorState']['selection'] ?? null;
	if ( ! is_array( $selection ) ) {
		return '';
	}

	$positions = array(
		$selection['cursorPosition'] ?? null,
		$selection['cursorStartPosition'] ?? null,
		$selection['cursorEndPosition'] ?? null,
		$selection['start'] ?? null,
		$selection['end'] ?? null,
	);

	foreach ( $positions as $position ) {
		if ( ! is_array( $position ) ) {
			continue;
		}

		$type = $position['relativePosition']['type'] ?? null;
		if ( ! is_array( $type ) || ! isset( $type['client'], $type['clock'] ) ) {
			continue;
		}

		$client = (int) $type['client'];
		$clock  = (int) $type['clock'];
		if ( $client > 0 && $clock >= 0 ) {
			return $client . ':' . $clock;
		}
	}

	return '';
}

/**
 * Builds diagnostic context for a failed YDoc candidate scan.
 *
 * @param array<string,mixed> $room_request Current sync room request.
 * @return array<string,mixed>
 */
function auto_linker_ydoc_link_candidate_diagnostics( \Yjs\YDoc $doc, array $room_request ): array {
	$awareness_type_id = auto_linker_ydoc_awareness_text_type_id( $room_request );
	$awareness         = array(
		'type_id'  => $awareness_type_id,
		'resolved' => false,
	);

	if ( '' !== $awareness_type_id ) {
		try {
			$awareness_text_type = $doc->nestedSharedType( $awareness_type_id );
			if ( $awareness_text_type instanceof \Yjs\YNestedText ) {
				$awareness['resolved'] = true;
				$awareness['text']     = auto_linker_preview_text( $awareness_text_type->toString() );
			} elseif ( null === $awareness_text_type ) {
				$awareness['resolved_as'] = null;
			} else {
				$awareness['resolved_as'] = get_debug_type( $awareness_text_type );
			}
		} catch ( Throwable $exception ) {
			$awareness['error'] = $exception->getMessage();
		}

		if ( method_exists( $doc, 'debugStruct' ) ) {
			try {
				$awareness['struct'] = $doc->debugStruct( $awareness_type_id );
			} catch ( Throwable $exception ) {
				$awareness['struct_error'] = $exception->getMessage();
			}
		}
	}

	$paragraphs = auto_linker_ydoc_collect_paragraphs( $doc );
	$previews   = array();
	foreach ( array_slice( $paragraphs, -8 ) as $paragraph ) {
		$text = (string) ( $paragraph['text'] ?? '' );
		$preview = array(
			'path'          => (string) ( $paragraph['path'] ?? '' ),
			'length'        => strlen( $text ),
			'has_link_term' => (bool) auto_linker_ydoc_candidate_match( $paragraph ),
			'text'          => auto_linker_preview_text( $text ),
		);
		if ( 'serialized_paragraph_html' === ( $paragraph['match_mode'] ?? '' ) ) {
			$preview['serialized_paragraphs'] = auto_linker_serialized_paragraph_diagnostics( $text );
		}
		$previews[] = $preview;
	}

	return array(
		'awareness'         => $awareness,
		'root_keys'         => array_keys( $doc->toJSON() ),
		'paragraph_count'   => count( $paragraphs ),
		'paragraph_previews' => $previews,
	);
}

/**
 * Truncates diagnostic text while preserving enough context for logs.
 */
function auto_linker_preview_text( string $text, int $limit = 160 ): string {
	$text = preg_replace( '/\s+/', ' ', $text ) ?? $text;
	if ( strlen( $text ) <= $limit ) {
		return $text;
	}

	return substr( $text, 0, $limit ) . '...';
}

/**
 * Builds paragraph-level diagnostics for serialized post content.
 *
 * @return array<int,array{index:int,length:int,has_link_term:bool,text:string}>
 */
function auto_linker_serialized_paragraph_diagnostics( string $text ): array {
	if ( ! preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $text, $paragraphs, PREG_OFFSET_CAPTURE ) ) {
		return array();
	}

	$diagnostics = array();
	foreach ( array_slice( $paragraphs[1], 0, 8 ) as $index => $paragraph ) {
		$inner_html = (string) $paragraph[0];
		$diagnostics[] = array(
			'index'         => $index,
			'length'        => strlen( $inner_html ),
			'has_link_term' => (bool) auto_linker_find_first_unlinked_term( $inner_html, auto_linker_get_terms() ),
			'text'          => auto_linker_preview_text( $inner_html, 120 ),
		);
	}

	return $diagnostics;
}

/**
 * Posts a bot-generated update to the Gutenberg sync endpoint.
 */
function auto_linker_post_bot_update( int $post_id, string $room, int $bot_client_id, string $update_data, array $awareness ) {
	$previous_user_id = get_current_user_id();
	wp_set_current_user( auto_linker_get_bot_user_id() );

	$request = new WP_REST_Request( 'POST', '/wp-sync/v1/updates' );
	$request->set_body_params(
		array(
			'rooms' => array(
				array(
					'after'     => 0,
					'awareness' => $awareness,
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

	return $response->is_error() ? $response->as_error() : $response;
}

/**
 * Finds the most recent paragraph nested Y.Text with a linkable term.
 *
 * @return array{text_type:\Yjs\YNestedText,text:string,path:string}|null
 */
function auto_linker_ydoc_find_first_link_candidate( \Yjs\YDoc $doc ): ?array {
	$paragraphs = auto_linker_ydoc_collect_paragraphs( $doc );
	for ( $index = count( $paragraphs ) - 1; $index >= 0; $index-- ) {
		$paragraph = $paragraphs[ $index ];
		if ( auto_linker_ydoc_candidate_match( $paragraph ) ) {
			return $paragraph;
		}
	}

	return null;
}

/**
 * Finds a linkable match for a YDoc text candidate.
 *
 * @param array{text:string,path?:string,match_mode?:string} $candidate Candidate metadata.
 * @return array{term:string,url:string,matched_text:string,start:int,length:int,replacement:string,opening_text:string,closing_text:string}|null
 */
function auto_linker_ydoc_candidate_match( array $candidate ): ?array {
	$text       = (string) ( $candidate['text'] ?? '' );
	$match_mode = (string) ( $candidate['match_mode'] ?? '' );
	if ( '' === $match_mode && str_contains( $text, '<!-- wp:' ) && str_contains( $text, '<p' ) ) {
		$match_mode = 'serialized_paragraph_html';
	}

	return 'serialized_paragraph_html' === $match_mode
		? auto_linker_find_first_serialized_paragraph_term( $text, auto_linker_get_terms() )
		: auto_linker_find_first_unlinked_term( $text, auto_linker_get_terms() );
}

/**
 * Collects paragraph nested Y.Text handles from the materialized Gutenberg YDoc.
 *
 * @return array<int,array{text_type:\Yjs\YNestedText,text:string,path:string,match_mode?:string}>
 */
function auto_linker_ydoc_collect_paragraphs( \Yjs\YDoc $doc ): array {
	$paragraphs = array();
	foreach ( $doc->toJSON() as $name => $value ) {
		try {
			$shared = is_array( $value ) && array_is_list( $value )
				? $doc->getArray( (string) $name )
				: ( is_array( $value ) ? $doc->getMap( (string) $name ) : null );
		} catch ( Throwable $exception ) {
			$shared = null;
		}
		auto_linker_ydoc_collect_paragraphs_in_shared_type( $shared, (string) $name, $paragraphs );
	}

	return $paragraphs;
}

/**
 * @param array<int,array{text_type:\Yjs\YNestedText,text:string,path:string,match_mode?:string}> $paragraphs Collected paragraph refs.
 */
function auto_linker_ydoc_collect_paragraphs_in_map( $map, string $path, array &$paragraphs ): void {
	$name = $map->get( 'name' );
	if ( 'core/paragraph' === $name ) {
		$text_type = auto_linker_ydoc_paragraph_content_text( $map );
		if ( $text_type ) {
			$paragraphs[] = array(
				'text_type' => $text_type,
				'text'      => $text_type->toString(),
				'path'      => $path . '.attributes.content',
			);
		}
	}

	foreach ( $map->entries() as $key => $value ) {
		unset( $value );
		try {
			$shared = $map->getSharedType( (string) $key );
		} catch ( Throwable $exception ) {
			$shared = null;
		}

		if ( 'document' === $path && 'content' === (string) $key && $shared instanceof \Yjs\YNestedText ) {
			$paragraphs[] = array(
				'text_type'  => $shared,
				'text'       => $shared->toString(),
				'path'       => $path . '.content',
				'match_mode' => 'serialized_paragraph_html',
			);
		}

		auto_linker_ydoc_collect_paragraphs_in_shared_type( $shared, $path . '.' . (string) $key, $paragraphs );
	}
}

/**
 * @param array<int,array{text_type:\Yjs\YNestedText,text:string,path:string,match_mode?:string}> $paragraphs Collected paragraph refs.
 */
function auto_linker_ydoc_collect_paragraphs_in_array( \Yjs\YNestedArray|\Yjs\YArray $array, string $path, array &$paragraphs ): void {
	for ( $index = 0; $index < $array->length(); $index++ ) {
		try {
			$shared = $array->getSharedType( $index );
		} catch ( Throwable $exception ) {
			$shared = null;
		}
		auto_linker_ydoc_collect_paragraphs_in_shared_type( $shared, $path . '[' . $index . ']', $paragraphs );
	}
}

/**
 * @param array<int,array{text_type:\Yjs\YNestedText,text:string,path:string,match_mode?:string}> $paragraphs Collected paragraph refs.
 */
function auto_linker_ydoc_collect_paragraphs_in_shared_type( $shared, string $path, array &$paragraphs ): void {
	if ( $shared instanceof \Yjs\YNestedMap || $shared instanceof \Yjs\YMap ) {
		auto_linker_ydoc_collect_paragraphs_in_map( $shared, $path, $paragraphs );
		return;
	}

	if ( $shared instanceof \Yjs\YNestedArray || $shared instanceof \Yjs\YArray ) {
		auto_linker_ydoc_collect_paragraphs_in_array( $shared, $path, $paragraphs );
	}
}

/**
 * Gets the paragraph content text shared type from a Gutenberg paragraph map.
 */
function auto_linker_ydoc_paragraph_content_text( $paragraph_map ): ?\Yjs\YNestedText {
	try {
		$attributes = $paragraph_map->getMap( 'attributes' );
		if ( $attributes ) {
			$content = $attributes->getText( 'content' );
			if ( $content ) {
				return $content;
			}
		}
	} catch ( Throwable $exception ) {
	}

	try {
		$content = $paragraph_map->getText( 'content' );
		if ( $content ) {
			return $content;
		}
	} catch ( Throwable $exception ) {
	}

	return null;
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
 * Finds a configured term inside serialized paragraph HTML.
 *
 * @param array<int,array{term:string,url:string}> $terms Terms.
 * @return array{term:string,url:string,matched_text:string,start:int,length:int,replacement:string,opening_text:string,closing_text:string}|null
 */
function auto_linker_find_first_serialized_paragraph_term( string $text, array $terms ): ?array {
	if ( ! preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $text, $paragraphs, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}

	foreach ( $paragraphs[1] as $paragraph ) {
		$inner_html        = (string) $paragraph[0];
		$inner_byte_offset = (int) $paragraph[1];
		$match             = auto_linker_find_first_unlinked_term( $inner_html, $terms );
		if ( ! $match ) {
			continue;
		}

		$match['start'] += \Auto_Linker\Gutenberg_RTC\gutenberg_yjs_utf16_clock_len( substr( $text, 0, $inner_byte_offset ) );

		return $match;
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
 * Builds bot awareness without an active selection.
 */
function auto_linker_build_bot_awareness_state( WP_User $bot_user, int $post_id, string $changed_text ): array {
	return array(
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
		array(
			'schema_version' => AUTO_LINKER_ROOM_STATE_SCHEMA_VERSION,
			'ydoc_update_v2' => '',
			'pending_link'   => null,
			'selection_until' => 0,
		),
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
