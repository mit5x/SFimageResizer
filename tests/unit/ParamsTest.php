<?php
/**
 * Parameter parsing and normalisation tests (spec 11.1.4).
 *
 * @package SFimageResizer
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers SFIR_Core::parse_params() and SFIR_Core::normalize_color().
 */
class ParamsTest extends TestCase {

	/**
	 * Defaults are applied to an empty parameter string.
	 *
	 * @return void
	 */
	public function test_defaults() {
		$params = SFIR_Core::parse_params( '' );

		$this->assertSame( 0, $params['w'] );
		$this->assertSame( 0, $params['h'] );
		$this->assertSame( 'webp', $params['f'] );
		$this->assertSame( 75, $params['q'] );
		$this->assertSame( '', $params['bg'] );
		$this->assertSame( 0, $params['crop'] );
	}

	/**
	 * Spec 11.1.4: quality is clamped into the 1-95 range.
	 *
	 * @return void
	 */
	public function test_quality_is_clamped() {
		$this->assertSame( 95, SFIR_Core::parse_params( 'q=200' )['q'] );
		$this->assertSame( 1, SFIR_Core::parse_params( 'q=0' )['q'] );
		$this->assertSame( 1, SFIR_Core::parse_params( 'q=-40' )['q'] );
		$this->assertSame( 95, SFIR_Core::parse_params( 'q=95' )['q'] );
		$this->assertSame( 60, SFIR_Core::parse_params( 'q=60' )['q'] );
	}

	/**
	 * Spec 11.1.4: an unknown format falls back to webp and reports a notice.
	 *
	 * @return void
	 */
	public function test_unknown_format_falls_back_to_webp() {
		$params = SFIR_Core::parse_params( 'f=bmp' );

		$this->assertSame( 'webp', $params['f'] );
		$this->assertNotEmpty( $params['notices'] );
	}

	/**
	 * Known formats are accepted, "jpeg" being an alias of "jpg".
	 *
	 * @return void
	 */
	public function test_known_formats() {
		$this->assertSame( 'jpg', SFIR_Core::parse_params( 'f=jpg' )['f'] );
		$this->assertSame( 'jpg', SFIR_Core::parse_params( 'f=JPEG' )['f'] );
		$this->assertSame( 'webp', SFIR_Core::parse_params( 'f=WebP' )['f'] );
		$this->assertEmpty( SFIR_Core::parse_params( 'f=jpg' )['notices'] );
	}

	/**
	 * Spec 11.1.4: a malformed background colour is ignored.
	 *
	 * @return void
	 */
	public function test_invalid_background_is_ignored() {
		$this->assertSame( '', SFIR_Core::parse_params( 'bg=GGGGGG' )['bg'] );
		$this->assertSame( '', SFIR_Core::parse_params( 'bg=12345' )['bg'] );
		$this->assertSame( '', SFIR_Core::parse_params( 'bg=' )['bg'] );
		$this->assertSame( '', SFIR_Core::parse_params( 'bg=red' )['bg'] );
	}

	/**
	 * Valid background colours are normalised to six uppercase digits.
	 *
	 * @return void
	 */
	public function test_background_normalisation() {
		$this->assertSame( 'FFFFFF', SFIR_Core::parse_params( 'bg=fff' )['bg'] );
		$this->assertSame( 'FF0000', SFIR_Core::parse_params( 'bg=FF0000' )['bg'] );
		$this->assertSame( 'AABBCC', SFIR_Core::parse_params( 'bg=%23aabbcc' )['bg'] );
		$this->assertSame( '112233', SFIR_Core::normalize_color( '#123' ) );
	}

	/**
	 * Spec 11.1.4: dimensions are clamped to the hard ceiling.
	 *
	 * @return void
	 */
	public function test_dimensions_are_clamped() {
		$this->assertSame( 5000, SFIR_Core::parse_params( 'w=99999' )['w'] );
		$this->assertSame( 5000, SFIR_Core::parse_params( 'h=99999' )['h'] );
		$this->assertSame( 0, SFIR_Core::parse_params( 'w=-10' )['w'] );
		$this->assertSame( 900, SFIR_Core::parse_params( 'w=900' )['w'] );
		$this->assertSame( 900, SFIR_Core::parse_params( 'w=900.7' )['w'] );
	}

	/**
	 * Spec 11.1.4: an unknown parameter changes neither the parameters nor the file name.
	 *
	 * @return void
	 */
	public function test_unknown_parameters_are_dropped() {
		$with    = SFIR_Core::parse_params( 'w=900&foo=1&bar=baz' );
		$without = SFIR_Core::parse_params( 'w=900' );

		$this->assertSame( $without, $with );
		$this->assertSame(
			SFIR_Core::build_cache_filename( 'photo.jpg', $without ),
			SFIR_Core::build_cache_filename( 'photo.jpg', $with )
		);
	}

	/**
	 * The crop flag accepts the usual truthy and falsy spellings.
	 *
	 * @return void
	 */
	public function test_crop_flag() {
		$this->assertSame( 1, SFIR_Core::parse_params( 'w=10&h=10&crop=1' )['crop'] );
		$this->assertSame( 0, SFIR_Core::parse_params( 'w=10&h=10&crop=0' )['crop'] );
		$this->assertSame( 0, SFIR_Core::parse_params( 'w=10&h=10' )['crop'] );
		$this->assertSame( 0, SFIR_Core::parse_params( 'w=10&h=10&crop=' )['crop'] );
	}

	/**
	 * Arrays are accepted as well as query strings.
	 *
	 * @return void
	 */
	public function test_array_input() {
		$this->assertSame(
			SFIR_Core::parse_params( 'w=900&h=600&crop=1&f=jpg&q=80' ),
			SFIR_Core::parse_params(
				array(
					'w'    => 900,
					'h'    => 600,
					'crop' => 1,
					'f'    => 'jpg',
					'q'    => 80,
				)
			)
		);
	}

	/**
	 * Garbage input degrades to the defaults instead of raising an error.
	 *
	 * @return void
	 */
	public function test_garbage_input() {
		foreach ( array( null, false, true, 12345, '???', '&&&', '=' ) as $input ) {
			$params = SFIR_Core::parse_params( $input );

			$this->assertSame( 'webp', $params['f'] );
			$this->assertSame( 75, $params['q'] );
		}
	}

	/**
	 * The canonical parameter string has a fixed key order.
	 *
	 * @return void
	 */
	public function test_canonical_string() {
		$this->assertSame(
			'w=900&h=0&f=webp&q=75&crop=0&bg=',
			SFIR_Core::params_to_string( SFIR_Core::parse_params( 'q=75&w=900&f=webp' ) )
		);
	}
}
