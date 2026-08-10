<?php
/**
 * Placeholder sizing and rendering tests.
 *
 * @package SFimageResizer
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers SFIR_Placeholder.
 */
class PlaceholderTest extends TestCase {

	/**
	 * Placeholder sizes follow the requested dimensions.
	 *
	 * @return void
	 */
	public function test_sizes() {
		$this->assertSame( array( 'width' => 300, 'height' => 200 ), SFIR_Placeholder::get_size( 0, 0 ) );
		$this->assertSame( array( 'width' => 900, 'height' => 400 ), SFIR_Placeholder::get_size( 900, 400 ) );
		$this->assertSame( array( 'width' => 900, 'height' => 600 ), SFIR_Placeholder::get_size( 900, 0 ) );
		$this->assertSame( array( 'width' => 600, 'height' => 400 ), SFIR_Placeholder::get_size( 0, 400 ) );
		$this->assertSame( array( 'width' => 5000, 'height' => 200 ), SFIR_Placeholder::get_size( 999999, 200 ) );
	}

	/**
	 * Unknown error codes collapse onto E01.
	 *
	 * @return void
	 */
	public function test_code_normalisation() {
		$this->assertSame( 'E05', SFIR_Placeholder::normalize_code( 'e05' ) );
		$this->assertSame( 'E01', SFIR_Placeholder::normalize_code( 'E99' ) );
		$this->assertSame( 'E01', SFIR_Placeholder::normalize_code( '<script>' ) );
		$this->assertSame( 'E01', SFIR_Placeholder::normalize_code( '' ) );
	}

	/**
	 * The SVG carries the size and the code, and contains no script.
	 *
	 * @return void
	 */
	public function test_render() {
		$svg = SFIR_Placeholder::render( 'E02', 640, 480 );

		$this->assertStringStartsWith( '<svg ', $svg );
		$this->assertStringContainsString( 'width="640"', $svg );
		$this->assertStringContainsString( 'height="480"', $svg );
		$this->assertStringContainsString( 'E02', $svg );
		$this->assertStringContainsString( 'Image error', $svg );
		$this->assertStringNotContainsString( '<script', $svg );
		$this->assertStringNotContainsString( 'href', $svg );
	}

	/**
	 * A hostile code cannot inject markup into the SVG.
	 *
	 * @return void
	 */
	public function test_render_is_not_injectable() {
		$svg = SFIR_Placeholder::render( '"><script>alert(1)</script>', 100, 100 );

		$this->assertStringNotContainsString( '<script', $svg );
		$this->assertStringContainsString( 'E01', $svg );
	}

	/**
	 * Every documented error code has a description.
	 *
	 * @return void
	 */
	public function test_all_codes_documented() {
		$codes = SFIR_Placeholder::get_codes();

		foreach ( array( 'E01', 'E02', 'E03', 'E04', 'E05', 'E06', 'E07' ) as $code ) {
			$this->assertArrayHasKey( $code, $codes );
			$this->assertNotEmpty( $codes[ $code ] );
		}
	}
}
