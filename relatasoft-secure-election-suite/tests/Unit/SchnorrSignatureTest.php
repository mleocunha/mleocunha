<?php
declare(strict_types=1);

/**
 * Schnorr sign/verify against portable Domain crypto (A1).
 *
 * @package RelataSoft\SecureElectionSuite\Tests\Unit
 */

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ElGamal;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\SchnorrSignature;

final class SchnorrSignatureTest extends TestCase {

	protected function setUp(): void {
		if ( ! extension_loaded( 'gmp' ) ) {
			$this->markTestSkipped( 'GMP extension required.' );
		}
	}

	public function test_sign_and_verify_roundtrip(): void {
		$keypair = ElGamal::generateKeyPair( 512 );
		$pub     = $keypair->getPublicGmp();
		$x       = $keypair->getPrivateGmp();
		$msg     = hash( 'sha256', 'rses-phpunit-schnorr' );

		$sig = SchnorrSignature::sign(
			$msg,
			$pub['p'],
			$pub['q'],
			$pub['g'],
			$x,
			$pub['y']
		);

		$this->assertTrue(
			SchnorrSignature::verify(
				$msg,
				$sig,
				$pub['p'],
				$pub['q'],
				$pub['g'],
				$pub['y']
			)
		);

		$this->assertFalse(
			SchnorrSignature::verify(
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
