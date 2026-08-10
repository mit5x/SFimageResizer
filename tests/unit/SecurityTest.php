<?php
/**
 * Signature and path safety tests (spec 11.1.6 and 11.1.7).
 *
 * @package SFimageResizer
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers SFIR_Security::sign()/verify() and SFIR_Core path normalisation.
 */
class SecurityTest extends TestCase {

	/**
	 * Secret used for the signature tests.
	 */
	const SECRET = 'unit-test-secret-0123456789abcdef0123456789abcdef';

	/**
	 * Spec 11.1.6: a valid signature is accepted.
	 *
	 * @return void
	 */
	public function test_valid_signature_passes() {
		$params  = SFIR_Core::parse_params( 'w=900&h=600&crop=1&q=80&f=jpg' );
		$payload = SFIR_Core::signature_payload( 'u:2026/08/photo.jpg', $params );

		$signature = SFIR_Security::sign( $payload, self::SECRET );

		$this->assertTrue( SFIR_Security::verify( $payload, $signature, self::SECRET ) );
		$this->assertMatchesRegularExpression( '#^[a-f0-9]{64}$#', $signature );
	}

	/**
	 * Spec 11.1.6: changing a single character of any parameter breaks the signature.
	 *
	 * @return void
	 */
	public function test_tampered_parameters_fail() {
		$key       = 'u:2026/08/photo.jpg';
		$original  = SFIR_Core::parse_params( 'w=900&h=600&crop=1&q=80&f=jpg' );
		$signature = SFIR_Security::sign( SFIR_Core::signature_payload( $key, $original ), self::SECRET );

		$tampered = array(
			'w=901&h=600&crop=1&q=80&f=jpg',
			'w=900&h=601&crop=1&q=80&f=jpg',
			'w=900&h=600&crop=0&q=80&f=jpg',
			'w=900&h=600&crop=1&q=81&f=jpg',
			'w=900&h=600&crop=1&q=80&f=webp',
			'w=900&h=600&crop=1&q=80&f=jpg&bg=FF0000',
		);

		foreach ( $tampered as $variant ) {
			$payload = SFIR_Core::signature_payload( $key, SFIR_Core::parse_params( $variant ) );

			$this->assertFalse(
				SFIR_Security::verify( $payload, $signature, self::SECRET ),
				'Signature should not validate for: ' . $variant
			);
		}
	}

	/**
	 * Changing the source path breaks the signature too.
	 *
	 * @return void
	 */
	public function test_tampered_source_fails() {
		$params    = SFIR_Core::parse_params( 'w=900' );
		$signature = SFIR_Security::sign( SFIR_Core::signature_payload( 'u:2026/08/photo.jpg', $params ), self::SECRET );

		$this->assertFalse(
			SFIR_Security::verify( SFIR_Core::signature_payload( 'u:2026/08/photo2.jpg', $params ), $signature, self::SECRET )
		);
		$this->assertFalse(
			SFIR_Security::verify( SFIR_Core::signature_payload( 'a:wp-config.php', $params ), $signature, self::SECRET )
		);
	}

	/**
	 * An empty or malformed signature is refused.
	 *
	 * @return void
	 */
	public function test_missing_signature_fails() {
		$payload = SFIR_Core::signature_payload( 'u:a.jpg', SFIR_Core::parse_params( '' ) );

		$this->assertFalse( SFIR_Security::verify( $payload, '', self::SECRET ) );
		$this->assertFalse( SFIR_Security::verify( $payload, 'deadbeef', self::SECRET ) );
		$this->assertFalse( SFIR_Security::verify( $payload, null, self::SECRET ) );
	}

	/**
	 * A different secret does not validate.
	 *
	 * @return void
	 */
	public function test_other_secret_fails() {
		$payload   = SFIR_Core::signature_payload( 'u:a.jpg', SFIR_Core::parse_params( 'w=100' ) );
		$signature = SFIR_Security::sign( $payload, self::SECRET );

		$this->assertFalse( SFIR_Security::verify( $payload, $signature, 'another-secret' ) );
	}

	/**
	 * Spec 11.1.7: traversal attempts are rejected.
	 *
	 * @return void
	 */
	public function test_path_traversal_is_rejected() {
		$attacks = array(
			'../../wp-config.php',
			'..%2f..%2fwp-config.php',
			'%2e%2e%2f%2e%2e%2fwp-config.php',
			'%2e%2e/%2e%2e/wp-config.php',
			'2026/../../../wp-config.php',
			'..\\..\\wp-config.php',
			"2026/08/photo\0.jpg",
			"2026/08/%00photo.jpg",
			'..',
			'./..',
			'',
			'/',
			'///',
		);

		foreach ( $attacks as $attack ) {
			$this->assertFalse(
				SFIR_Core::normalize_relative_path( $attack ),
				'Should have been rejected: ' . str_replace( "\0", '\\0', $attack )
			);
		}
	}

	/**
	 * Legitimate relative paths survive normalisation.
	 *
	 * @return void
	 */
	public function test_legitimate_paths_pass() {
		$this->assertSame( '2026/08/photo.jpg', SFIR_Core::normalize_relative_path( '2026/08/photo.jpg' ) );
		$this->assertSame( '2026/08/photo.jpg', SFIR_Core::normalize_relative_path( '/2026/08/photo.jpg' ) );
		$this->assertSame( '2026/08/photo.jpg', SFIR_Core::normalize_relative_path( '2026//08/./photo.jpg' ) );
		$this->assertSame( '2026/08/my photo.jpg', SFIR_Core::normalize_relative_path( '2026/08/my%20photo.jpg' ) );
	}

	/**
	 * Directory containment is decided on the resolved path.
	 *
	 * @return void
	 */
	public function test_path_containment() {
		$this->assertTrue( SFIR_Core::is_path_within( '/var/www/uploads/a.jpg', '/var/www/uploads' ) );
		$this->assertTrue( SFIR_Core::is_path_within( '/var/www/uploads/x/a.jpg', '/var/www/uploads/' ) );
		$this->assertTrue( SFIR_Core::is_path_within( '/var/www/uploads', '/var/www/uploads' ) );

		$this->assertFalse( SFIR_Core::is_path_within( '/var/www/uploads-evil/a.jpg', '/var/www/uploads' ) );
		$this->assertFalse( SFIR_Core::is_path_within( '/var/www/wp-config.php', '/var/www/uploads' ) );
		$this->assertFalse( SFIR_Core::is_path_within( '/etc/passwd', '/var/www/uploads' ) );
		$this->assertFalse( SFIR_Core::is_path_within( '', '/var/www/uploads' ) );
		$this->assertFalse( SFIR_Core::is_path_within( '/var/www/uploads/a.jpg', '' ) );
	}

	/**
	 * A source key can only address the two allowed roots.
	 *
	 * @return void
	 */
	public function test_source_key_shape() {
		$params = SFIR_Core::parse_params( 'w=100' );

		$this->assertSame(
			'w=100&h=0&f=webp&q=75&crop=0&bg=|u:2026/08/a.jpg',
			SFIR_Core::signature_payload( 'u:2026/08/a.jpg', $params )
		);
	}
}
