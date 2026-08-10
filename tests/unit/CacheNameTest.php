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
			'photo-900x0-c0-q75.webp',
			SFIR_Core::build_cache_filename( 'photo.jpg', SFIR_Core::parse_params( 'w=900' ) )
		);

		$this->assertSame(
			'photo-600x400-c1-q80.jpg',
			SFIR_Core::build_cache_filename( 'photo.png', SFIR_Core::parse_params( 'w=600&h=400&crop=1&q=80&f=jpg' ) )
		);
	}

	/**
	 * An explicit background colour becomes part of the name.
	 *
	 * @return void
	 */
	public function test_background_suffix() {
		$this->assertSame(
			'photo-600x400-c0-q75-bgFF0000.webp',
			SFIR_Core::build_cache_filename( 'photo.png', SFIR_Core::parse_params( 'w=600&h=400&bg=ff0000' ) )
		);

		$this->assertSame(
			'photo-600x400-c0-q75.webp',
			SFIR_Core::build_cache_filename( 'photo.png', SFIR_Core::parse_params( 'w=600&h=400' ) )
		);
	}

	/**
	 * Spec 11.1.5: the name is deterministic.
	 *
	 * @return void
	 */
	public function test_deterministic() {
		$params = SFIR_Core::parse_params( 'w=900&h=300&crop=1&q=42&bg=abc&f=jpg' );

		$first = SFIR_Core::build_cache_filename( 'my photo.JPG', $params );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( $first, SFIR_Core::build_cache_filename( 'my photo.JPG', $params ) );
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
			$filename = SFIR_Core::build_cache_filename( $name, SFIR_Core::parse_params( 'w=100&h=100&crop=1' ) );

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
			$names[] = SFIR_Core::build_cache_filename( 'photo.jpg', SFIR_Core::parse_params( $variant ) );
		}

		$this->assertSame( count( $names ), count( array_unique( $names ) ) );
	}

	/**
	 * The mirrored cache directory is sanitised segment by segment.
	 *
	 * @return void
	 */
	public function test_relative_dir_is_sanitised() {
		$this->assertSame( '2026/08', SFIR_Core::sanitize_relative_dir( '2026/08/photo.jpg' ) );
		$this->assertSame( '', SFIR_Core::sanitize_relative_dir( 'photo.jpg' ) );
		$this->assertMatchesRegularExpression(
			'#^[A-Za-z0-9._/-]*$#',
			SFIR_Core::sanitize_relative_dir( 'фото/2026 год/photo.jpg' )
		);
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
