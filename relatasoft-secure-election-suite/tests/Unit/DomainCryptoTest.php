<?php
declare(strict_types=1);

/**
 * Portable Domain crypto suite (A1) — no WordPress boot.
 *
 * @package RelataSoft\SecureElectionSuite\Tests\Unit
 */

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\CanonicalHash;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\CryptoException;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ElGamal;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ElGamalCiphertext;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\HomomorphicTally;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\SchnorrSignature;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ShamirSecretSharing;

final class DomainCryptoTest extends TestCase {

	protected function setUp(): void {
		if ( ! extension_loaded( 'gmp' ) ) {
			$this->markTestSkipped( 'GMP extension required.' );
		}
	}

	public function test_elgamal_encrypt_decrypt_roundtrip(): void {
		$keypair = ElGamal::generateKeyPair( 512 );
		$pub     = $keypair->getPublicGmp();
		$x       = $keypair->getPrivateGmp();
		$ct      = ElGamal::encrypt( \gmp_init( 7 ), $pub['p'], $pub['q'], $pub['g'], $pub['y'] );
		$plain   = ElGamal::decrypt( $ct, $pub['p'], $x );
		$this->assertSame( 0, \gmp_cmp( $plain, \gmp_init( 7 ) ) );
	}

	public function test_homomorphic_aggregation(): void {
		$keypair = ElGamal::generateKeyPair( 512 );
		$pub     = $keypair->getPublicGmp();
		$x       = $keypair->getPrivateGmp();
		$votes   = array(
			HomomorphicTally::encryptCount( 1, $pub['p'], $pub['q'], $pub['g'], $pub['y'] ),
			HomomorphicTally::encryptCount( 1, $pub['p'], $pub['q'], $pub['g'], $pub['y'] ),
			HomomorphicTally::encryptCount( 0, $pub['p'], $pub['q'], $pub['g'], $pub['y'] ),
		);
		$sum = HomomorphicTally::aggregateCounts( $votes, $pub['p'] );
		$n   = HomomorphicTally::decryptAndDecode( $sum, $pub['p'], $pub['q'], $pub['g'], $x, 10 );
		$this->assertSame( 2, $n );
	}

	public function test_shamir_split_reconstruct_and_threshold(): void {
		$secret = \gmp_init( 123456789 );
		$prime  = \gmp_nextprime( \gmp_mul( $secret, \gmp_init( 10 ) ) );
		$shares = ShamirSecretSharing::splitSecret( $secret, 3, 5, $prime );
		$this->assertCount( 5, $shares );

		$got = ShamirSecretSharing::reconstructWithThreshold( array_slice( $shares, 0, 3 ), $prime, 3 );
		$this->assertSame( 0, \gmp_cmp( $got, $secret ) );

		$this->expectException( CryptoException::class );
		ShamirSecretSharing::reconstructWithThreshold( array_slice( $shares, 0, 2 ), $prime, 3 );
	}

	public function test_shamir_share_payload_checksum_roundtrip(): void {
		$keypair = ElGamal::generateKeyPair( 512 );
		$pub     = array(
			'p' => $keypair->getP(),
			'q' => $keypair->getQ(),
			'g' => $keypair->getG(),
			'y' => $keypair->getY(),
		);
		$payload = ShamirSecretSharing::buildSharePayload(
			1,
			1,
			3,
			5,
			\gmp_init( 10007 ),
			1,
			\gmp_init( 42 ),
			$pub
		);
		$this->assertArrayHasKey( 'checksum', $payload );
		ShamirSecretSharing::validateSharePayload( $payload );

		$bad               = $payload;
		$bad['share_value'] = '1';
		$this->expectException( CryptoException::class );
		ShamirSecretSharing::validateSharePayload( $bad );
	}

	public function test_schnorr_and_challenge_deterministic(): void {
		$keypair = ElGamal::generateKeyPair( 512 );
		$pub     = $keypair->getPublicGmp();
		$x       = $keypair->getPrivateGmp();
		$msg     = hash( 'sha256', 'domain-crypto-a1' );
		$sig     = SchnorrSignature::sign( $msg, $pub['p'], $pub['q'], $pub['g'], $x, $pub['y'] );
		$this->assertTrue( SchnorrSignature::verify( $msg, $sig, $pub['p'], $pub['q'], $pub['g'], $pub['y'] ) );

		$r  = BigInt::fromDecimalString( $sig['r'] );
		$e1 = SchnorrSignature::rses_challenge( $r, $msg, $pub['q'] );
		$e2 = SchnorrSignature::rses_challenge( $r, $msg, $pub['q'] );
		$this->assertSame( 0, \gmp_cmp( $e1, $e2 ) );
	}

	public function test_ciphertext_decimal_roundtrip(): void {
		$ct   = new ElGamalCiphertext( \gmp_init( 3 ), \gmp_init( 5 ) );
		$arr  = $ct->toDecimalArray();
		$back = ElGamalCiphertext::fromDecimalStrings( $arr['alpha'], $arr['beta'] );
		$this->assertSame( 0, \gmp_cmp( $ct->getAlpha(), $back->getAlpha() ) );
		$this->assertSame( 0, \gmp_cmp( $ct->getBeta(), $back->getBeta() ) );
	}

	public function test_canonical_hash_stable_across_mysql_string_types(): void {
		$a = array(
			'actor_user_id' => 7,
			'action'        => 'certification',
			'object_type'   => 'certification',
			'object_id'     => 11,
			'previous_hash' => null,
			'payload_json'  => '{}',
			'created_at'    => '2026-08-28 12:00:00',
		);
		$b = array(
			'actor_user_id' => '7',
			'action'        => 'certification',
			'object_type'   => 'certification',
			'object_id'     => '11',
			'previous_hash' => '',
			'payload_json'  => '{}',
			'created_at'    => '2026-08-28 12:00:00',
		);
		$this->assertSame(
			CanonicalHash::rses_hash_audit_entry( $a ),
			CanonicalHash::rses_hash_audit_entry( $b )
		);
		$this->assertSame( 64, strlen( CanonicalHash::rses_hash_json( array( 'k' => 'v' ) ) ) );
	}

	public function test_bigint_mod_mul(): void {
		$a = \gmp_init( 7 );
		$b = \gmp_init( 5 );
		$m = \gmp_init( 11 );
		$this->assertSame( 0, \gmp_cmp( BigInt::modMul( $a, $b, $m ), \gmp_init( 2 ) ) );
	}
}
