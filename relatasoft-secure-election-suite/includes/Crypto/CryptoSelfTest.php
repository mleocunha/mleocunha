<?php
/**
 * Cryptographic self-test suite.
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-accessible crypto self-tests.
 */
class CryptoSelfTest {

	/**
	 * Run all self-tests.
	 *
	 * @return array<int,array{name:string,passed:bool,message:string,details?:array<string,mixed>}>
	 */
	public static function runAll(): array {
		return array(
			self::testGmpAvailable(),
			self::testElGamalKeyGeneration(),
			self::testEncryptDecrypt(),
			self::testHomomorphicAggregation(),
			self::testFeldmanVss(),
			self::testFullMiniElection(),
		);
	}

	/**
	 * Test 1: GMP available.
	 *
	 * @return array{name:string,passed:bool,message:string}
	 */
	public static function testGmpAvailable(): array {
		$rses_passed = extension_loaded( 'gmp' );

		return array(
			'name'    => __( 'GMP Extension', 'relatasoft-secure-election-suite' ),
			'passed'  => $rses_passed,
			'message' => $rses_passed
				? __( 'GMP extension is loaded.', 'relatasoft-secure-election-suite' )
				: __( 'GMP extension is not available.', 'relatasoft-secure-election-suite' ),
		);
	}

	/**
	 * Test 2: ElGamal key generation.
	 *
	 * @return array{name:string,passed:bool,message:string}
	 */
	public static function testElGamalKeyGeneration(): array {
		if ( ! extension_loaded( 'gmp' ) ) {
			return array(
				'name'    => __( 'ElGamal Key Generation', 'relatasoft-secure-election-suite' ),
				'passed'  => false,
				'message' => __( 'Skipped: GMP not available.', 'relatasoft-secure-election-suite' ),
			);
		}

		try {
			$rses_keypair = ElGamal::generateKeyPair( 512 );
			$rses_pub     = $rses_keypair->getPublicGmp();

			$rses_p = $rses_pub['p'];
			$rses_q = $rses_pub['q'];
			$rses_g = $rses_pub['g'];
			$rses_x = $rses_keypair->getPrivateGmp();
			$rses_y = $rses_pub['y'];

			$rses_gq = BigInt::modPow( $rses_g, $rses_q, $rses_p );
			$rses_expected_y = BigInt::modPow( $rses_g, $rses_x, $rses_p );

			if ( \gmp_cmp( $rses_gq, \gmp_init( 1 ) ) !== 0 ) {
				return array(
					'name'    => __( 'ElGamal Key Generation', 'relatasoft-secure-election-suite' ),
					'passed'  => false,
					'message' => __( 'g^q mod p != 1', 'relatasoft-secure-election-suite' ),
				);
			}

			if ( \gmp_cmp( $rses_y, $rses_expected_y ) !== 0 ) {
				return array(
					'name'    => __( 'ElGamal Key Generation', 'relatasoft-secure-election-suite' ),
					'passed'  => false,
					'message' => __( 'y != g^x mod p', 'relatasoft-secure-election-suite' ),
				);
			}

			return array(
				'name'    => __( 'ElGamal Key Generation', 'relatasoft-secure-election-suite' ),
				'passed'  => true,
				'message' => __( '512-bit development key generated and validated.', 'relatasoft-secure-election-suite' ),
			);
		} catch ( CryptoException $rses_e ) {
			return array(
				'name'    => __( 'ElGamal Key Generation', 'relatasoft-secure-election-suite' ),
				'passed'  => false,
				'message' => $rses_e->getMessage(),
			);
		}
	}

	/**
	 * Test 3: Encrypt/decrypt round trip.
	 *
	 * @return array{name:string,passed:bool,message:string}
	 */
	public static function testEncryptDecrypt(): array {
		if ( ! extension_loaded( 'gmp' ) ) {
			return array(
				'name'    => __( 'Encrypt/Decrypt', 'relatasoft-secure-election-suite' ),
				'passed'  => false,
				'message' => __( 'Skipped: GMP not available.', 'relatasoft-secure-election-suite' ),
			);
		}

		try {
			$rses_keypair = ElGamal::generateKeyPair( 512 );
			$rses_pub     = $rses_keypair->getPublicGmp();

			$rses_encoded = CryptoEncoding::encodeCount( 1, $rses_pub['g'], $rses_pub['p'] );
			$rses_ct      = ElGamal::encrypt( $rses_encoded, $rses_pub['p'], $rses_pub['q'], $rses_pub['g'], $rses_pub['y'] );
			$rses_dec     = ElGamal::decrypt( $rses_ct, $rses_pub['p'], $rses_keypair->getPrivateGmp() );

			if ( \gmp_cmp( $rses_dec, $rses_encoded ) !== 0 ) {
				return array(
					'name'    => __( 'Encrypt/Decrypt', 'relatasoft-secure-election-suite' ),
					'passed'  => false,
					'message' => __( 'Decrypted value does not match original.', 'relatasoft-secure-election-suite' ),
				);
			}

			return array(
				'name'    => __( 'Encrypt/Decrypt', 'relatasoft-secure-election-suite' ),
				'passed'  => true,
				'message' => __( 'Encrypt/decrypt round trip successful.', 'relatasoft-secure-election-suite' ),
			);
		} catch ( CryptoException $rses_e ) {
			return array(
				'name'    => __( 'Encrypt/Decrypt', 'relatasoft-secure-election-suite' ),
				'passed'  => false,
				'message' => $rses_e->getMessage(),
			);
		}
	}

	/**
	 * Test 4: Homomorphic aggregation.
	 *
	 * @return array{name:string,passed:bool,message:string}
	 */
	public static function testHomomorphicAggregation(): array {
		if ( ! extension_loaded( 'gmp' ) ) {
			return array(
				'name'    => __( 'Homomorphic Aggregation', 'relatasoft-secure-election-suite' ),
				'passed'  => false,
				'message' => __( 'Skipped: GMP not available.', 'relatasoft-secure-election-suite' ),
			);
		}

		try {
			$rses_keypair = ElGamal::generateKeyPair( 512 );
			$rses_pub     = $rses_keypair->getPublicGmp();
			$rses_votes   = array( 1, 1, 0 );
			$rses_cts     = array();

			foreach ( $rses_votes as $rses_vote ) {
				$rses_cts[] = HomomorphicTally::encryptCount(
					$rses_vote,
					$rses_pub['p'],
					$rses_pub['q'],
					$rses_pub['g'],
					$rses_pub['y']
				);
			}

			$rses_agg   = HomomorphicTally::aggregateCounts( $rses_cts, $rses_pub['p'] );
			$rses_count = HomomorphicTally::decryptAndDecode(
				$rses_agg,
				$rses_pub['p'],
				$rses_pub['q'],
				$rses_pub['g'],
				$rses_keypair->getPrivateGmp(),
				3
			);

			if ( 2 !== $rses_count ) {
				return array(
					'name'    => __( 'Homomorphic Aggregation', 'relatasoft-secure-election-suite' ),
					'passed'  => false,
					'message' => sprintf(
						/* translators: %d: decoded count */
						__( 'Expected count 2, got %d.', 'relatasoft-secure-election-suite' ),
						$rses_count
					),
				);
			}

			return array(
				'name'    => __( 'Homomorphic Aggregation', 'relatasoft-secure-election-suite' ),
				'passed'  => true,
				'message' => __( 'Votes [1,1,0] aggregated to count 2.', 'relatasoft-secure-election-suite' ),
			);
		} catch ( CryptoException $rses_e ) {
			return array(
				'name'    => __( 'Homomorphic Aggregation', 'relatasoft-secure-election-suite' ),
				'passed'  => false,
				'message' => $rses_e->getMessage(),
			);
		}
	}

	/**
	 * Test 5: Shamir Secret Sharing.
	 *
	 * @return array{name:string,passed:bool,message:string}
	 */
	public static function testFeldmanVss(): array {
		if ( ! extension_loaded( 'gmp' ) ) {
			return array(
				'name'    => __( 'Feldman VSS', 'relatasoft-secure-election-suite' ),
				'passed'  => false,
				'message' => __( 'Skipped: GMP not available.', 'relatasoft-secure-election-suite' ),
			);
		}

		try {
			$rses_keypair = ElGamal::generateKeyPair( 512 );
			$rses_pub     = $rses_keypair->getPublicGmp();
			$rses_x       = $rses_keypair->getPrivateGmp();

			$split = FeldmanVss::rses_split_with_commitments(
				$rses_x,
				3,
				5,
				$rses_pub['p'],
				$rses_pub['q'],
				$rses_pub['g']
			);

			if ( \gmp_cmp( $split['commitments'][0], $rses_pub['y'] ) !== 0 ) {
				return array(
					'name'    => __( 'Feldman VSS', 'relatasoft-secure-election-suite' ),
					'passed'  => false,
					'message' => __( 'Commitment C0 does not equal public key y.', 'relatasoft-secure-election-suite' ),
				);
			}

			foreach ( $split['shares'] as $share ) {
				$ok = FeldmanVss::rses_verify_share(
					(int) $share['x'],
					$share['y'],
					$split['commitments'],
					$rses_pub['p'],
					$rses_pub['q'],
					$rses_pub['g'],
					$rses_pub['y']
				);
				if ( ! $ok ) {
					return array(
						'name'    => __( 'Feldman VSS', 'relatasoft-secure-election-suite' ),
						'passed'  => false,
						'message' => __( 'Share failed Feldman verification.', 'relatasoft-secure-election-suite' ),
					);
				}
			}

			$rses_subset = array(
				$split['shares'][0],
				$split['shares'][2],
				$split['shares'][4],
			);
			$rses_reconstructed = Polynomial::rses_reconstruct_with_threshold( $rses_subset, $rses_pub['q'], 3 );

			if ( \gmp_cmp( $rses_reconstructed, $rses_x ) !== 0 ) {
				return array(
					'name'    => __( 'Feldman VSS', 'relatasoft-secure-election-suite' ),
					'passed'  => false,
					'message' => __( 'Reconstructed secret does not match original.', 'relatasoft-secure-election-suite' ),
				);
			}

			try {
				Polynomial::rses_reconstruct_with_threshold(
					array( $split['shares'][0], $split['shares'][1] ),
					$rses_pub['q'],
					3
				);
				return array(
					'name'    => __( 'Feldman VSS', 'relatasoft-secure-election-suite' ),
					'passed'  => false,
					'message' => __( 'Reconstruction with 2 shares should have failed.', 'relatasoft-secure-election-suite' ),
				);
			} catch ( CryptoException $rses_e ) {
				// Expected failure.
			}

			return array(
				'name'    => __( 'Feldman VSS', 'relatasoft-secure-election-suite' ),
				'passed'  => true,
				'message' => __( 't=3 n=5 Feldman VSS split/verify/reconstruct validated (field = q).', 'relatasoft-secure-election-suite' ),
			);
		} catch ( CryptoException $rses_e ) {
			return array(
				'name'    => __( 'Feldman VSS', 'relatasoft-secure-election-suite' ),
				'passed'  => false,
				'message' => $rses_e->getMessage(),
			);
		}
	}

	/**
	 * Test 6: Full mini election simulation.
	 *
	 * @return array{name:string,passed:bool,message:string}
	 */
	public static function testFullMiniElection(): array {
		if ( ! extension_loaded( 'gmp' ) ) {
			return array(
				'name'    => __( 'Mini Election Simulation', 'relatasoft-secure-election-suite' ),
				'passed'  => false,
				'message' => __( 'Skipped: GMP not available.', 'relatasoft-secure-election-suite' ),
			);
		}

		try {
			$rses_keypair = ElGamal::generateKeyPair( 512 );
			$rses_pub     = $rses_keypair->getPublicGmp();
			$rses_x       = $rses_keypair->getPrivateGmp();

			$split = FeldmanVss::rses_split_with_commitments(
				$rses_x,
				3,
				5,
				$rses_pub['p'],
				$rses_pub['q'],
				$rses_pub['g']
			);
			$rses_votes = array( 1, 1, 1, 0, 0 );
			$rses_cts   = array();

			foreach ( $rses_votes as $rses_vote ) {
				$rses_cts[] = HomomorphicTally::encryptCount(
					$rses_vote,
					$rses_pub['p'],
					$rses_pub['q'],
					$rses_pub['g'],
					$rses_pub['y']
				);
			}

			$rses_agg = HomomorphicTally::aggregateCounts( $rses_cts, $rses_pub['p'] );

			$rses_threshold_shares = array(
				$split['shares'][0],
				$split['shares'][2],
				$split['shares'][4],
			);

			$rses_reconstructed_x = Polynomial::rses_reconstruct_with_threshold(
				$rses_threshold_shares,
				$rses_pub['q'],
				3
			);

			$rses_y_check = BigInt::modPow( $rses_pub['g'], $rses_reconstructed_x, $rses_pub['p'] );
			if ( \gmp_cmp( $rses_y_check, $rses_pub['y'] ) !== 0 ) {
				return array(
					'name'    => __( 'Mini Election Simulation', 'relatasoft-secure-election-suite' ),
					'passed'  => false,
					'message' => __( 'Reconstructed x failed g^x mod p == y validation.', 'relatasoft-secure-election-suite' ),
				);
			}

			$rses_count = HomomorphicTally::decryptAndDecode(
				$rses_agg,
				$rses_pub['p'],
				$rses_pub['q'],
				$rses_pub['g'],
				$rses_reconstructed_x,
				5
			);

			unset( $rses_reconstructed_x, $rses_x );

			if ( 3 !== $rses_count ) {
				return array(
					'name'    => __( 'Mini Election Simulation', 'relatasoft-secure-election-suite' ),
					'passed'  => false,
					'message' => sprintf(
						/* translators: %d: decoded count */
						__( 'Expected 3 yes votes, got %d.', 'relatasoft-secure-election-suite' ),
						$rses_count
					),
				);
			}

			return array(
				'name'    => __( 'Mini Election Simulation', 'relatasoft-secure-election-suite' ),
				'passed'  => true,
				'message' => __( 'Full mini election with Feldman VSS threshold decryption passed.', 'relatasoft-secure-election-suite' ),
			);
		} catch ( CryptoException $rses_e ) {
			return array(
				'name'    => __( 'Mini Election Simulation', 'relatasoft-secure-election-suite' ),
				'passed'  => false,
				'message' => $rses_e->getMessage(),
			);
		}
	}

	/**
	 * Check if debug mode allows showing sensitive values.
	 *
	 * @return bool
	 */
	public static function rses_debug_enabled(): bool {
		return defined( 'RSES_DEBUG_CRYPTO' ) && RSES_DEBUG_CRYPTO;
	}
}
