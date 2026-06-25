<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function add_action( ...$args ): void {}
function add_filter( ...$args ): void {}
function register_setting( ...$args ): void {}
function add_settings_section( ...$args ): void {}
function add_settings_field( ...$args ): void {}
function add_options_page( ...$args ): void {}
function __( string $text, string $domain = 'default' ): string {
	return $text;
}
function esc_html__( string $text, string $domain = 'default' ): string {
	return $text;
}
function esc_attr_e( string $text, string $domain = 'default' ): void {
	echo $text;
}
function esc_html_e( string $text, string $domain = 'default' ): void {
	echo $text;
}
function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( string $url ): string {
	return $url;
}
function esc_url_raw( string $url ): string {
	return $url;
}
function sanitize_text_field( string $text ): string {
	return trim( $text );
}
function absint( mixed $value ): int {
	return max( 0, (int) $value );
}
function get_option( string $name, mixed $default = false ): mixed {
	return $default;
}
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
	return json_encode( $value, $flags, $depth );
}

require_once dirname( __DIR__ ) . '/auto-linker.php';

function auto_linker_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function auto_linker_test_terms(): array {
	return array(
		array(
			'term' => 'Playground',
			'url'  => 'https://playground.wordpress.net/',
		),
	);
}

function auto_linker_test_doc_with_blocks_paragraph( string $content ): \Yjs\YDoc {
	$doc       = new \Yjs\YDoc( 1001 );
	$blocks    = $doc->getArray( 'blocks' );
	$paragraph = $blocks->appendMap();
	$paragraph->set( 'name', 'core/paragraph' );
	$attributes = $paragraph->setMap( 'attributes' );
	$text       = $attributes->setText( 'content' );
	$text->insert( 0, $content );

	return $doc;
}

function auto_linker_test_doc_with_serialized_document_content( string $content ): \Yjs\YDoc {
	$doc      = new \Yjs\YDoc( 1005 );
	$document = $doc->getMap( 'document' );
	$text     = $document->setText( 'content' );
	$text->insert( 0, $content );

	return $doc;
}

function auto_linker_test_compacted_copy( \Yjs\YDoc $doc ): \Yjs\YDoc {
	$copy = new \Yjs\YDoc( 1003 );
	$copy->applyUpdateV2( $doc->encodeStateAsUpdateV2(), 'test-compaction' );

	return $copy;
}

$tests = array(
	'finds paragraph content below root blocks array' => static function (): void {
		$doc       = auto_linker_test_doc_with_blocks_paragraph( 'Hello Playground!' );
		$candidate = auto_linker_ydoc_find_first_link_candidate( $doc );

		auto_linker_test_assert( is_array( $candidate ), 'Expected a paragraph candidate.' );
		auto_linker_test_assert( 'Hello Playground!' === $candidate['text'], 'Expected candidate text to come from paragraph attributes.content.' );
		auto_linker_test_assert( false !== str_contains( $candidate['path'], 'blocks' ), 'Expected candidate path to start from the blocks root.' );
	},

	'does not use serialized root content as a candidate' => static function (): void {
		$doc = new \Yjs\YDoc( 1002 );
		$doc->insertText( 'content', 0, '<!-- wp:paragraph --><p>Playground</p><!-- /wp:paragraph -->' );

		auto_linker_test_assert( null === auto_linker_ydoc_find_first_link_candidate( $doc ), 'Root serialized content must not be a mutation candidate.' );
	},

	'finds repeated playground terms after compaction' => static function (): void {
		$text      = 'and another playground and another playground and another playground and another playground.';
		$doc       = auto_linker_test_compacted_copy( auto_linker_test_doc_with_blocks_paragraph( $text ) );
		$candidate = auto_linker_ydoc_find_first_link_candidate( $doc );

		auto_linker_test_assert( is_array( $candidate ), 'Expected a paragraph candidate after compaction.' );
		auto_linker_test_assert( $text === $candidate['text'], 'Expected compacted paragraph text to preserve repeated playground terms.' );
		auto_linker_test_assert( is_array( auto_linker_find_first_unlinked_term( $candidate['text'], auto_linker_test_terms() ) ), 'Expected repeated playground text to be linkable.' );
	},

	'does not use repeated playground terms in serialized root content' => static function (): void {
		$doc = new \Yjs\YDoc( 1004 );
		$doc->insertText( 'content', 0, '<!-- wp:paragraph --><p>and another playground and another playground and another playground and another playground.</p><!-- /wp:paragraph -->' );

		auto_linker_test_assert( null === auto_linker_ydoc_find_first_link_candidate( $doc ), 'Repeated playground text in serialized root content must not be a mutation candidate.' );
	},

	'uses serialized document content paragraph fallback' => static function (): void {
		$text      = '<!-- wp:paragraph --><p>and another playground and another playground.</p><!-- /wp:paragraph -->';
		$doc       = auto_linker_test_doc_with_serialized_document_content( $text );
		$candidate = auto_linker_ydoc_find_first_link_candidate( $doc );

		auto_linker_test_assert( is_array( $candidate ), 'Expected serialized document.content paragraph candidate.' );
		auto_linker_test_assert( 'document.content' === $candidate['path'], 'Expected candidate path to be document.content.' );
		auto_linker_test_assert( 'serialized_paragraph_html' === ( $candidate['match_mode'] ?? '' ), 'Expected serialized paragraph matcher.' );
		auto_linker_test_assert( is_array( auto_linker_find_first_serialized_paragraph_term( $candidate['text'], auto_linker_test_terms() ) ), 'Expected serialized paragraph text to be linkable.' );
	},

	'does not use serialized document content outside paragraphs' => static function (): void {
		$doc = auto_linker_test_doc_with_serialized_document_content( '<!-- wp:heading --><h2>Playground</h2><!-- /wp:heading -->' );

		auto_linker_test_assert( null === auto_linker_ydoc_find_first_link_candidate( $doc ), 'Serialized document.content fallback must be constrained to paragraph HTML.' );
	},

	'matches standalone terms case-insensitively' => static function (): void {
		$match = auto_linker_find_first_unlinked_term( 'hi WordPress, this is playground! ', auto_linker_test_terms() );

		auto_linker_test_assert( is_array( $match ), 'Expected lowercase playground to match Playground.' );
		auto_linker_test_assert( 'playground' === $match['matched_text'], 'Expected matched text to preserve source case.' );
	},

	'does not match terms inside larger words' => static function (): void {
		$match = auto_linker_find_first_unlinked_term( 'wordplayground highlighted', auto_linker_test_terms() );

		auto_linker_test_assert( null === $match, 'Expected wordplayground not to match Playground.' );
	},

	'applies compaction room entries as yjs updates' => static function (): void {
		$source = auto_linker_test_doc_with_blocks_paragraph( 'Hello Playground!' );
		$target = new \Yjs\YDoc( 1006 );

		auto_linker_ydoc_apply_room_update(
			$target,
			array(
				'type' => 'compaction',
				'data' => base64_encode( $source->encodeStateAsUpdateV2() ),
			),
			'test-room'
		);

		$candidate = auto_linker_ydoc_find_first_link_candidate( $target );
		auto_linker_test_assert( is_array( $candidate ), 'Expected candidate after compaction apply.' );
		auto_linker_test_assert( 'Hello Playground!' === $candidate['text'], 'Expected compaction entry to hydrate paragraph text.' );
	},

	'applies sync step 2 room entries as yjs updates' => static function (): void {
		$source  = auto_linker_test_doc_with_blocks_paragraph( 'Hello Playground!' );
		$target  = new \Yjs\YDoc( 1007 );
		$message = \Yjs\Sync\SyncProtocol::writeSyncStep2V2( $source );

		auto_linker_ydoc_apply_room_update(
			$target,
			array(
				'type' => 'sync_step2',
				'data' => base64_encode( $message ),
			),
			'test-room'
		);

		$candidate = auto_linker_ydoc_find_first_link_candidate( $target );
		auto_linker_test_assert( is_array( $candidate ), 'Expected candidate after sync step 2 apply.' );
		auto_linker_test_assert( 'Hello Playground!' === $candidate['text'], 'Expected sync step 2 entry to hydrate paragraph text.' );
	},
);

$failures = 0;
foreach ( $tests as $name => $test ) {
	try {
		$test();
		echo "PASS {$name}\n";
	} catch ( Throwable $exception ) {
		$failures++;
		echo "FAIL {$name}: {$exception->getMessage()}\n";
	}
}

if ( $failures > 0 ) {
	exit( 1 );
}
