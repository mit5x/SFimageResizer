<?php
/**
 * Tests for the decision of when a resized copy is produced.
 *
 * @package SFimageResizer
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers the mode resolution and the rendering budget.
 */
class GeneratorTest extends TestCase {

	/**
	 * Starts every test from an untouched option store and budget.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['sfir_test_options'] = array();
		$GLOBALS['sfir_test_filters'] = array();
		SFIR_Diagnostics::$confirmed  = 0;

		SFIR_Generator::reset_budget();
	}

	/**
	 * The automatic mode is what a fresh installation gets.
	 *
	 * @return void
	 */
	public function test_mode_defaults_to_auto() {
		$this->assertSame( SFIR_Generator::MODE_AUTO, SFIR_Generator::get_mode() );
	}

	/**
	 * A stored value that is not a mode is ignored.
	 *
	 * @return void
	 */
	public function test_unknown_stored_mode_falls_back_to_auto() {
		update_option( SFIR_Generator::MODE_OPTION, 'whenever-it-feels-like-it' );

		$this->assertSame( SFIR_Generator::MODE_AUTO, SFIR_Generator::get_mode() );
	}

	/**
	 * Only the three documented modes can be stored.
	 *
	 * @return void
	 */
	public function test_set_mode_rejects_anything_else() {
		SFIR_Generator::set_mode( SFIR_Generator::MODE_ALWAYS );
		$this->assertSame( SFIR_Generator::MODE_ALWAYS, SFIR_Generator::get_mode() );

		SFIR_Generator::set_mode( 'sometimes' );
		$this->assertSame( SFIR_Generator::MODE_AUTO, SFIR_Generator::get_mode() );
	}

	/**
	 * "Always" and "never" do not consult the configuration check.
	 *
	 * @return void
	 */
	public function test_explicit_modes_ignore_the_confirmation() {
		SFIR_Generator::set_mode( SFIR_Generator::MODE_ALWAYS );
		SFIR_Diagnostics::$confirmed = time();
		SFIR_Generator::reset_budget();

		$this->assertTrue( SFIR_Generator::is_eager(), 'always stays eager even once confirmed' );

		SFIR_Generator::set_mode( SFIR_Generator::MODE_NEVER );
		SFIR_Diagnostics::$confirmed = 0;
		SFIR_Generator::reset_budget();

		$this->assertFalse( SFIR_Generator::is_eager(), 'never stays lazy even while unconfirmed' );
	}

	/**
	 * The automatic mode produces copies until the request path is proven.
	 *
	 * @return void
	 */
	public function test_auto_follows_the_confirmation() {
		SFIR_Generator::set_mode( SFIR_Generator::MODE_AUTO );

		SFIR_Diagnostics::$confirmed = 0;
		SFIR_Generator::reset_budget();
		$this->assertTrue( SFIR_Generator::is_eager(), 'eager while nothing is confirmed' );

		SFIR_Diagnostics::$confirmed = time();
		SFIR_Generator::reset_budget();
		$this->assertFalse( SFIR_Generator::is_eager(), 'lazy once the check has confirmed the request path' );
	}

	/**
	 * The answer is worked out once per request, not once per image.
	 *
	 * @return void
	 */
	public function test_the_decision_is_memoised() {
		SFIR_Generator::set_mode( SFIR_Generator::MODE_AUTO );
		SFIR_Generator::reset_budget();

		$this->assertTrue( SFIR_Generator::is_eager() );

		// A confirmation arriving mid-request must not change the answer under
		// the images already rendered above it.
		SFIR_Diagnostics::$confirmed = time();

		$this->assertTrue( SFIR_Generator::is_eager(), 'the memoised answer is reused' );
	}

	/**
	 * A fresh request may do work.
	 *
	 * @return void
	 */
	public function test_budget_starts_available() {
		$this->assertTrue( SFIR_Generator::has_budget() );

		$spent = SFIR_Generator::get_spent();

		$this->assertSame( 0, $spent['files'] );
		$this->assertSame( 0.0, $spent['seconds'] );
	}

	/**
	 * The budget is a positive number of files and seconds.
	 *
	 * @return void
	 */
	public function test_budget_is_sane() {
		$budget = SFIR_Generator::get_budget();

		$this->assertArrayHasKey( 'files', $budget );
		$this->assertArrayHasKey( 'seconds', $budget );
		$this->assertGreaterThan( 0, $budget['files'] );
		$this->assertGreaterThan( 0.0, $budget['seconds'] );
		$this->assertIsInt( $budget['files'] );
		$this->assertIsFloat( $budget['seconds'] );
	}

	/**
	 * A budget of nothing stops the very first file.
	 *
	 * @return void
	 */
	public function test_an_empty_budget_stops_everything() {
		$GLOBALS['sfir_test_filters']['sfir_generation_budget'] = function () {
			return array(
				'files'   => 0,
				'seconds' => 0.0,
			);
		};

		$this->assertFalse( SFIR_Generator::has_budget() );
	}
}
