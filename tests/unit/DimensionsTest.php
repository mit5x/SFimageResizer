<?php
/**
 * Geometry calculation tests (spec 11.1.1 - 11.1.3).
 *
 * @package SFimageResizer
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers SFIR_Core::calculate_dimensions().
 */
class DimensionsTest extends TestCase {

	/**
	 * Helper: parses a parameter string and returns the resulting geometry.
	 *
	 * @param int    $src_w  Source width.
	 * @param int    $src_h  Source height.
	 * @param string $params Parameter string.
	 * @return array
	 */
	protected function geometry( $src_w, $src_h, $params ) {
		return SFIR_Core::calculate_dimensions( $src_w, $src_h, SFIR_Core::parse_params( $params ) );
	}

	/**
	 * Helper: returns the output size as a "WxH" string.
	 *
	 * @param int    $src_w  Source width.
	 * @param int    $src_h  Source height.
	 * @param string $params Parameter string.
	 * @return string
	 */
	protected function output( $src_w, $src_h, $params ) {
		$geometry = $this->geometry( $src_w, $src_h, $params );

		return $geometry['dst_w'] . 'x' . $geometry['dst_h'];
	}

	/**
	 * Spec 11.1.1: a single side scales the other one proportionally.
	 *
	 * @return void
	 */
	public function test_single_side_scales_proportionally() {
		$this->assertSame( '900x450', $this->output( 2000, 1000, 'w=900&h=0' ) );
		$this->assertSame( '900x450', $this->output( 2000, 1000, 'w=0&h=450' ) );
	}

	/**
	 * Spec 11.1.1: both sides without crop means "fit inside the box".
	 *
	 * @return void
	 */
	public function test_fit_inside_box() {
		$this->assertSame( '900x450', $this->output( 2000, 1000, 'w=900&h=900&crop=0' ) );
	}

	/**
	 * Spec 11.1.1: both sides with crop means "fill the box exactly".
	 *
	 * @return void
	 */
	public function test_crop_fills_box() {
		$this->assertSame( '900x900', $this->output( 2000, 1000, 'w=900&h=900&crop=1' ) );
	}

	/**
	 * The cropped region is centred on the source.
	 *
	 * @return void
	 */
	public function test_crop_region_is_centred() {
		$geometry = $this->geometry( 2000, 1000, 'w=900&h=900&crop=1' );

		$this->assertSame( 1000, $geometry['src_w'] );
		$this->assertSame( 1000, $geometry['src_h'] );
		$this->assertSame( 500, $geometry['src_x'] );
		$this->assertSame( 0, $geometry['src_y'] );
	}

	/**
	 * No parameters at all keeps the source size.
	 *
	 * @return void
	 */
	public function test_no_size_keeps_original() {
		$this->assertSame( '2000x1000', $this->output( 2000, 1000, '' ) );
		$this->assertSame( '2000x1000', $this->output( 2000, 1000, 'w=0&h=0&f=jpg' ) );
	}

	/**
	 * Spec 11.1.2: images are never enlarged.
	 *
	 * @return void
	 */
	public function test_never_upscales() {
		$this->assertSame( '400x300', $this->output( 400, 300, 'w=900' ) );
		$this->assertSame( '400x300', $this->output( 400, 300, 'w=0&h=900' ) );
		$this->assertSame( '400x300', $this->output( 400, 300, 'w=900&h=900&crop=0' ) );
	}

	/**
	 * Spec 11.1.2: a crop of a too small source keeps the requested ratio.
	 *
	 * @return void
	 */
	public function test_crop_without_upscaling() {
		$this->assertSame( '300x300', $this->output( 400, 300, 'w=900&h=900&crop=1' ) );

		$geometry = $this->geometry( 400, 300, 'w=900&h=900&crop=1' );
		$this->assertSame( 300, $geometry['src_w'] );
		$this->assertSame( 300, $geometry['src_h'] );
		$this->assertSame( 50, $geometry['src_x'] );
		$this->assertSame( 0, $geometry['src_y'] );
	}

	/**
	 * A crop to a non-square ratio on a small source keeps that ratio.
	 *
	 * @return void
	 */
	public function test_crop_keeps_requested_ratio_when_small() {
		// 16:9 requested from a 400x300 source: the widest 16:9 region is 400x225.
		$this->assertSame( '400x225', $this->output( 400, 300, 'w=1600&h=900&crop=1' ) );
	}

	/**
	 * Spec 11.1.3: crop is ignored when only one side is given.
	 *
	 * @return void
	 */
	public function test_crop_ignored_without_both_sides() {
		$this->assertSame(
			$this->output( 2000, 1000, 'w=900&h=0&crop=0' ),
			$this->output( 2000, 1000, 'w=900&h=0&crop=1' )
		);

		$this->assertSame( 0, SFIR_Core::parse_params( 'w=900&h=0&crop=1' )['crop'] );
		$this->assertSame( 0, SFIR_Core::parse_params( 'w=0&h=900&crop=1' )['crop'] );
	}

	/**
	 * An unusable source size yields an empty geometry instead of a fatal error.
	 *
	 * @return void
	 */
	public function test_invalid_source_size() {
		$geometry = $this->geometry( 0, 0, 'w=900' );

		$this->assertSame( 0, $geometry['dst_w'] );
		$this->assertSame( 0, $geometry['dst_h'] );
	}

	/**
	 * Rounding never produces a zero-sized output.
	 *
	 * @return void
	 */
	public function test_extreme_ratio_never_rounds_to_zero() {
		$geometry = $this->geometry( 4000, 3, 'w=10' );

		$this->assertSame( 10, $geometry['dst_w'] );
		$this->assertGreaterThanOrEqual( 1, $geometry['dst_h'] );
	}
}
