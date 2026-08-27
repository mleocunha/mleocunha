<?php
declare(strict_types=1);

/**
 * Focused Schnorr sign/verify test (ElGamal subgroup).
 *
 * @package RelataSoft\SecureElectionSuite\Tests\Unit
 */

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SchnorrSignatureTest extends TestCase {

	protected function setUp(): void {
		if ( ! extension_loaded( 'gmp' ) ) {
			$this->markTestSkipped( 'GMP extension required.' );
		}
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', true );
		}
		if ( ! function_exists( '__' ) ) {
			// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
			eval( 'function __( string $text, string $domain = "default" ): string { return $text; }' );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/Crypto/CryptoException.php';
		require_once dirname( __DIR__, 2 ) . '/includes/Crypto/BigInt.php';
		require_once dirname( __DIR__, 2 ) . '/includes/Crypto/CryptoRandom.php';
		require_once dirname( __DIR__, 2 ) . '/includes/Crypto/PrimeGenerator.php';
		require_once dirname( __DIR__, 2 ) . '/includes/Crypto/ElGamalCiphertext.php';
		require_once dirname( __DIR__, 2 ) . '/includes/Crypto/ElGamalKeyPair.php';
		require_once dirname( __DIR__, 2 ) . '/includes/Crypto/ElGamal.php';
		require_once dirname( __DIR__, 2 ) . '/includes/Crypto/SchnorrSignature.php';
	}

	public function test_sign_and_verify_roundtrip(): void {
		$keypair = \RelataSoft\SecureElectionSuite\Crypto\ElGamal::generateKeyPair( 512 );
		$pub     = $keypair->getPublicGmp();
		$x       = $keypair->getPrivateGmp();
		$msg     = hash( 'sha256', 'rses-phpunit-schnorr' );

		$sig = \RelataSoft\SecureElectionSuite\Crypto\SchnorrSignature::sign(
			$msg,
			$pub['p'],
			$pub['q'],
			$pub['g'],
			$x,
			$pub['y']
		);

		$this->assertTrue(
			\RelataSoft\SecureElectionSuite\Crypto\SchnorrSignature::verify(
				$msg,
				$sig,
				$pub['p'],
				$pub['q'],
				$pub['g'],
				$pub['y']
			)
		);

		$this->assertFalse(
			\RelataSoft\SecureElectionSuite\Crypto\SchnorrSignature::verify(
				hash( 'sha256', 'tampered' ),
				$sig,
				$pub['p'],
				$pub['q'],
				$pub['g'],
				$pub['y']
			)
		);
	}
}
