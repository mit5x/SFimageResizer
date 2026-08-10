<?php
/**
 * Cache file naming tests (spec 11.1.5).
 *
 * @package SFimageResizer
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers SFIR_Core::build_cache_filename() and the name sanitiser.
 */
class CacheNameTest extends TestCase {

	/**
	 * The documented naming scheme is produced.
	 *
	 * @return void
	 */
	public function test_documented_scheme() {
		$this->assertSame(
			'photo-900x0-c0-q75-a3f9c1.webp',
			SFIR_Core::build_cache_filename( 'photo.jpg', SFIR_Core::parse_params( 'w=900' ), 'a3f9c1' )
		);

		$this->assertSame(
			'photo-600x400-c1-q80-000abc.jpg',
			SFIR_Core::build_cache_filename( 'photo.png', SFIR_Core::parse_params( 'w=600&h=400&crop=1&q=80&f=jpg' ), '000abc' )
		);

		$this->assertSame(
			'photo-900x0-c0-q75.webp',
			SFIR_Core::build_cache_filename( 'photo.jpg', SFIR_Core::parse_params( 'w=900' ) ),
			'The hash is optional so the name can be built without a secret.'
		);
	}

	/**
	 * An explicit background colour becomes part of the name.
	 *
	 * @return void
	 */
	public function test_background_suffix() {
		$this->assertSame(
			'photo-600x400-c0-q75-bgFF0000-abc123.webp',
			SFIR_Core::build_cache_filename( 'photo.png', SFIR_Core::parse_params( 'w=600&h=400&bg=ff0000' ), 'abc123' )
		);

		$this->assertSame(
			'photo-600x400-c0-q75-abc123.webp',
			SFIR_Core::build_cache_filename( 'photo.png', SFIR_Core::parse_params( 'w=600&h=400' ), 'abc123' )
		);
	}

	/**
	 * Spec 11.1.5: the name is deterministic.
	 *
	 * @return void
	 */
	public function test_deterministic() {
		$params = SFIR_Core::parse_params( 'w=900&h=300&crop=1&q=42&bg=abc&f=jpg' );

		$first = SFIR_Core::build_cache_filename( 'my photo.JPG', $params, 'abc123' );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( $first, SFIR_Core::build_cache_filename( 'my photo.JPG', $params, 'abc123' ) );
		}
	}

	/**
	 * Spec 11.1.5: the name never leaves the safe character set.
	 *
	 * @return void
	 */
	public function test_character_set() {
		$names = array(
			'../../wp-config',
			'photo;rm -rf /.jpg',
			"null\0byte.png",
			'изображение.jpg',
			'a/b/c.png',
			'..',
			'',
			'.htaccess',
			str_repeat( 'x', 400 ) . '.jpg',
			'photo%2e%2e%2fsecret.jpg',
		);

		foreach ( $names as $name ) {
			$filename = SFIR_Core::build_cache_filename( $name, SFIR_Core::parse_params( 'w=100&h=100&crop=1' ), 'abc123' );

			$this->assertMatchesRegularExpression(
				'#^[A-Za-z0-9._-]+$#',
				$filename,
				'Unsafe characters produced for: ' . wp_json_encode_stub( $name )
			);

			$this->assertStringNotContainsString( '..', $filename );
			$this->assertStringEndsWith( '.webp', $filename );
		}
	}

	/**
	 * Different parameters produce different names.
	 *
	 * @return void
	 */
	public function test_names_are_distinct() {
		$variants = array(
			'w=900',
			'w=900&q=80',
			'w=900&h=900',
			'w=900&h=900&crop=1',
			'w=900&f=jpg',
			'w=900&bg=FFFFFF',
		);

		$names = array();

		foreach ( $variants as $variant ) {
			$names[] = SFIR_Core::build_cache_filename( 'photo.jpg', SFIR_Core::parse_params( $variant ), 'abc123' );
		}

		$this->assertSame( count( $names ), count( array_unique( $names ) ) );
	}

	/**
	 * The mirrored cache directory keeps the source tree verbatim.
	 *
	 * @return void
	 */
	public function test_relative_dir_mirrors_the_source_tree() {
		$this->assertSame( '2026/08', SFIR_Core::relative_dir( '2026/08/photo.jpg' ) );
		$this->assertSame( '', SFIR_Core::relative_dir( 'photo.jpg' ) );
		$this->assertSame( 'фото/2026 год', SFIR_Core::relative_dir( 'фото/2026 год/photo.jpg' ) );
	}

	/**
	 * Spec 11.1: a built name parses back into the values it was built from.
	 *
	 * @return void
	 */
	public function test_encode_decode_round_trip() {
		$cases = array(
			'w=900',
			'w=900&h=600&crop=1&q=80&f=jpg',
			'w=0&h=450',
			'w=600&h=400&bg=ff0000',
			'w=600&h=400&bg=fff&f=jpg&q=1',
			'w=5000&h=5000&crop=1&q=95',
			'w=1&h=1&crop=1&q=1&bg=000000',
			'',
		);

		foreach ( $cases as $case ) {
			$params   = SFIR_Core::parse_params( $case );
			$filename = SFIR_Core::build_cache_filename( 'photo.jpg', $params, 'a3f9c1' );

			$parsed = SFIR_Core::parse_cache_filename( $filename );

			$this->assertIsArray( $parsed, 'Should parse: ' . $filename );
			$this->assertSame( 'photo', $parsed['stem'], 'Stem for ' . $filename );
			$this->assertSame( 'a3f9c1', $parsed['hash'], 'Hash for ' . $filename );
			$this->assertSame( $params, $parsed['params'], 'Parameters for ' . $filename );

			$this->assertSame(
				$filename,
				SFIR_Core::build_cache_filename( $parsed['stem'], $parsed['params'], $parsed['hash'] ),
				'Rebuilding ' . $filename
			);
		}
	}

	/**
	 * A stem that itself looks like a suffix still round-trips.
	 *
	 * @return void
	 */
	public function test_round_trip_with_a_confusing_stem() {
		$params   = SFIR_Core::parse_params( 'w=200&h=100&crop=1&q=90' );
		$filename = SFIR_Core::build_cache_filename( 'photo-800x0-c0-q75-aaaaaa.webp', $params, 'bbbbbb' );

		$parsed = SFIR_Core::parse_cache_filename( $filename );

		$this->assertIsArray( $parsed );
		$this->assertSame( 'photo-800x0-c0-q75-aaaaaa', $parsed['stem'] );
		$this->assertSame( 'bbbbbb', $parsed['hash'] );
		$this->assertSame( $params, $parsed['params'] );
	}

	/**
	 * Names that are not ours are refused.
	 *
	 * @return void
	 */
	public function test_parser_rejects_foreign_names() {
		$bad = array(
			'photo.jpg',
			'photo-900x0-c0-q75.webp',
			'photo-900x0-c0-q75-abc12.webp',
			'photo-900x0-c0-q75-ABC123.webp',
			'photo-900x0-c0-q75-abc123.png',
			'photo-900x0-c2-q75-abc123.webp',
			'photo-900x0-c0-q99-abc123.webp',
			'photo-99999x0-c0-q75-abc123.webp',
			'photo-900x0-c1-q75-abc123.webp',
			'photo-900x600-c0-q75-bgZZZZZZ-abc123.webp',
			'../photo-900x0-c0-q75-abc123.webp',
			'',
		);

		foreach ( $bad as $name ) {
			$this->assertFalse( SFIR_Core::parse_cache_filename( $name ), 'Should be refused: ' . $name );
		}
	}

	/**
	 * URL paths are decoded segment by segment and traversal is refused.
	 *
	 * @return void
	 */
	public function test_decode_url_path() {
		$this->assertSame( array( '2026', '08', 'photo.webp' ), SFIR_Core::decode_url_path( '2026/08/photo.webp' ) );
		$this->assertSame( array( '2026', 'my photo.webp' ), SFIR_Core::decode_url_path( '2026/my%20photo.webp' ) );
		$this->assertSame( array( 'фото', 'a.webp' ), SFIR_Core::decode_url_path( '%D1%84%D0%BE%D1%82%D0%BE/a.webp' ) );

		foreach ( array( '..%2f..%2fwp-config.php', '2026/../../x.webp', '%2e%2e/x.webp', 'a/%2e%2e/b', '', '/', 'a/%2fb' ) as $attack ) {
			$this->assertFalse( SFIR_Core::decode_url_path( $attack ), 'Should be refused: ' . $attack );
		}
	}
}

/**
 * Tiny helper so the assertion messages stay readable without WordPress.
 *
 * @param mixed $value Value to describe.
 * @return string
 */
function wp_json_encode_stub( $value ) {
	return str_replace( "\0", '\\0', (string) $value );
}
