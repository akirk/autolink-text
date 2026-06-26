<?php
/**
 * Supporting admin, matching, YDoc, awareness, persistence, and logging helpers for Autolink Text.
 *
 * @package Autolink_Text
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Autolink Text settings.
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
		__( 'Bot identity', 'autolink-text' ),
		'__return_null',
		'auto_linker'
	);

	add_settings_field(
		AUTO_LINKER_OPTION_BOT_USER_ID,
		__( 'Bot user', 'autolink-text' ),
		'auto_linker_render_bot_user_field',
		'auto_linker',
		'auto_linker_bot_section'
	);

	add_settings_section(
		'auto_linker_terms_section',
		__( 'Terms', 'autolink-text' ),
		'__return_null',
		'auto_linker'
	);

	add_settings_field(
		AUTO_LINKER_OPTION_TERMS,
		__( 'Linked terms', 'autolink-text' ),
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
		__( 'Autolink Text', 'autolink-text' ),
		__( 'Autolink Text', 'autolink-text' ),
		'manage_options',
		'autolink-text',
		'auto_linker_render_settings_page'
	);
}

/**
 * Enqueues settings page assets.
 */
function auto_linker_enqueue_settings_assets( string $hook_suffix ): void {
	if ( 'settings_page_autolink-text' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );
	wp_add_inline_style(
		'wp-color-picker',
		'.settings_page_autolink-text .form-table th { padding-left: 10px; }
		.settings_page_autolink-text .wp-picker-container {
			position: relative;
		}
		.settings_page_autolink-text .wp-picker-holder {
			left: 0;
			position: absolute;
			top: 100%;
			z-index: 100000;
		}
		.settings_page_autolink-text .wp-picker-container .iris-picker {
			box-shadow: 0 8px 20px rgba(0, 0, 0, 0.18);
			margin-top: 6px;
		}'
	);
	wp_add_inline_script(
		'wp-color-picker',
		'jQuery( function( $ ) {
			function updatePreview( $row ) {
				var color = $row.find( ".auto-linker-text-color-field" ).val();
				var backgroundColor = $row.find( ".auto-linker-bg-color-field" ).val();
				var isBold = $row.find( ".auto-linker-bold-field" ).is( ":checked" );
				var $term = $row.find( ".auto-linker-term-field" );

				$term.css( {
					color: color || "",
					backgroundColor: backgroundColor || "",
					fontWeight: isBold ? "700" : ""
				} );
			}

			$( ".auto-linker-color-field" ).wpColorPicker( {
				change: function( event, ui ) {
					var $field = $( event.target );
					window.setTimeout( function() {
						$field.val( ui.color ? ui.color.toString() : "" );
						updatePreview( $field.closest( "tr" ) );
					}, 0 );
				},
				clear: function( event ) {
					var $field = $( event.target );
					window.setTimeout( function() {
						updatePreview( $field.closest( "tr" ) );
					}, 0 );
				}
			} );

			$( ".auto-linker-color-field" ).on( "input change", function() {
				updatePreview( $( this ).closest( "tr" ) );
			} );

			$( ".auto-linker-bold-field" ).on( "change", function() {
				updatePreview( $( this ).closest( "tr" ) );
			} );

			$( ".auto-linker-term-field" ).closest( "tr" ).each( function() {
				updatePreview( $( this ) );
			} );
		} );'
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
			'show_option_none'  => __( 'Select a user', 'autolink-text' ),
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
		'term'     => '',
		'url'      => '',
		'color'    => '',
		'bg_color' => '',
		'bold'     => false,
	);
	?>
	<table class="widefat striped" style="max-width: 1200px;">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Term', 'autolink-text' ); ?></th>
				<th scope="col"><?php esc_html_e( 'URL', 'autolink-text' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Color', 'autolink-text' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Background', 'autolink-text' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Bold', 'autolink-text' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $terms as $index => $term ) : ?>
				<?php $term_style = auto_linker_build_link_style( (string) ( $term['color'] ?? '' ), (string) ( $term['bg_color'] ?? '' ), ! empty( $term['bold'] ) ); ?>
				<tr>
					<td>
						<input
							type="text"
							name="<?php echo esc_attr( AUTO_LINKER_OPTION_TERMS ); ?>[<?php echo esc_attr( (string) $index ); ?>][term]"
							value="<?php echo esc_attr( $term['term'] ); ?>"
							class="regular-text auto-linker-term-field"
							placeholder="<?php esc_attr_e( 'Playground', 'autolink-text' ); ?>"
							<?php if ( '' !== $term_style ) : ?>
								style="<?php echo esc_attr( $term_style ); ?>"
							<?php endif; ?>
						/>
					</td>
					<td>
						<input
							type="url"
							name="<?php echo esc_attr( AUTO_LINKER_OPTION_TERMS ); ?>[<?php echo esc_attr( (string) $index ); ?>][url]"
							value="<?php echo esc_attr( $term['url'] ); ?>"
							class="large-text"
							placeholder="<?php esc_attr_e( 'https://playground.wordpress.net/', 'autolink-text' ); ?>"
						/>
					</td>
					<td>
						<input
							type="text"
							name="<?php echo esc_attr( AUTO_LINKER_OPTION_TERMS ); ?>[<?php echo esc_attr( (string) $index ); ?>][color]"
							value="<?php echo esc_attr( $term['color'] ?? '' ); ?>"
							class="auto-linker-color-field auto-linker-text-color-field"
							placeholder="<?php esc_attr_e( '#d63638', 'autolink-text' ); ?>"
							data-default-color=""
						/>
					</td>
					<td>
						<input
							type="text"
							name="<?php echo esc_attr( AUTO_LINKER_OPTION_TERMS ); ?>[<?php echo esc_attr( (string) $index ); ?>][bg_color]"
							value="<?php echo esc_attr( $term['bg_color'] ?? '' ); ?>"
							class="auto-linker-color-field auto-linker-bg-color-field"
							placeholder="<?php esc_attr_e( '#f6f7f7', 'autolink-text' ); ?>"
							data-default-color=""
						/>
					</td>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( AUTO_LINKER_OPTION_TERMS ); ?>[<?php echo esc_attr( (string) $index ); ?>][bold]"
								value="1"
								class="auto-linker-bold-field"
								<?php checked( ! empty( $term['bold'] ) ); ?>
							/>
							<?php esc_html_e( 'Bold', 'autolink-text' ); ?>
						</label>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'Leave a row blank to ignore it. Save once to add another empty row.', 'autolink-text' ); ?></p>
	<?php
}

/**
 * Renders the settings page.
 */
function auto_linker_render_settings_page(): void {
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p><?php esc_html_e( 'Choose the WordPress user Autolink Text should use when emitting PHP-generated Gutenberg RTC updates, then configure the terms it should link.', 'autolink-text' ); ?></p>
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
 * @return array<int,array{term:string,url:string,color:string,bg_color:string,bold:bool}>
 */
function auto_linker_get_terms(): array {
	return auto_linker_sanitize_terms( get_option( AUTO_LINKER_OPTION_TERMS, auto_linker_default_terms() ) );
}

/**
 * Gets the default linked terms.
 *
 * @return array<int,array{term:string,url:string,color:string,bg_color:string,bold:bool}>
 */
function auto_linker_default_terms(): array {
	return array(
		array(
			'term'     => 'Playground',
			'url'      => 'https://playground.wordpress.net/',
			'color'    => '',
			'bg_color' => '',
			'bold'     => false,
		),
	);
}

/**
 * Sanitizes term settings.
 *
 * @param mixed $value Posted option value.
 * @return array<int,array{term:string,url:string,color:string,bg_color:string,bold:bool}>
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

		$term     = isset( $row['term'] ) ? sanitize_text_field( (string) $row['term'] ) : '';
		$url      = isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '';
		$color    = isset( $row['color'] ) ? auto_linker_sanitize_link_color( (string) $row['color'] ) : '';
		$bg_color = isset( $row['bg_color'] ) ? auto_linker_sanitize_link_color( (string) $row['bg_color'] ) : '';
		$bold     = ! empty( $row['bold'] );
		if ( '' === $term || '' === $url ) {
			continue;
		}

		$terms[] = array(
			'term'     => $term,
			'url'      => $url,
			'color'    => $color,
			'bg_color' => $bg_color,
			'bold'     => $bold,
		);
	}

	return $terms ?: auto_linker_default_terms();
}

/**
 * Sanitizes a configured link color.
 */
function auto_linker_sanitize_link_color( string $color ): string {
	$color = trim( $color );
	if ( '' === $color ) {
		return '';
	}

	if ( '#' !== $color[0] ) {
		$color = '#' . $color;
	}

	return preg_match( '/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i', $color ) ? strtolower( $color ) : '';
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
 * Gets the stable RTC client ID used by Autolink Text for a bot user.
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
		return new WP_Error( 'auto_linker_missing_room', __( 'Missing Autolink Text room.', 'autolink-text' ) );
	}

	$bot_user_id = auto_linker_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return new WP_Error( 'auto_linker_missing_bot_user', __( 'No Autolink Text bot user is configured.', 'autolink-text' ) );
	}

	$bot_user = get_user_by( 'id', $bot_user_id );
	if ( ! $bot_user || ! user_can( $bot_user, 'edit_post', $post_id ) ) {
		return new WP_Error( 'auto_linker_bot_cannot_edit', __( 'The configured Autolink Text bot user cannot edit this post.', 'autolink-text' ) );
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
		return new WP_Error( 'auto_linker_missing_room', __( 'Missing Autolink Text room.', 'autolink-text' ) );
	}

	$bot_user_id = auto_linker_get_bot_user_id();
	if ( ! $bot_user_id ) {
		return new WP_Error( 'auto_linker_missing_bot_user', __( 'No Autolink Text bot user is configured.', 'autolink-text' ) );
	}

	$bot_user = get_user_by( 'id', $bot_user_id );
	if ( ! $bot_user || ! user_can( $bot_user, 'edit_post', $post_id ) ) {
		return new WP_Error( 'auto_linker_bot_cannot_edit', __( 'The configured Autolink Text bot user cannot edit this post.', 'autolink-text' ) );
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
		'browserType' => 'Autolink Text',
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
 * Gets Autolink Text's lightweight CRDT room state.
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
 * Stores Autolink Text's lightweight CRDT room state.
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
	$message = '[Autolink Text] ' . gmdate( 'c' ) . ' ' . $event . ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
