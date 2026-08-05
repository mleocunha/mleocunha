<?php
/**
 * Chunked key-generation pipeline runner.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Crypto\DeterministicRandom;
use RelataSoft\SecureElectionSuite\Crypto\ElGamal;
use RelataSoft\SecureElectionSuite\Crypto\ElGamalKeyPair;
use RelataSoft\SecureElectionSuite\Crypto\PrimeGenerator;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Advances one keygen job in wall-clock-bounded ticks (≤25s).
 *
 * Returns a flat public status payload for the admin AJAX UI.
 */
class KeyGenerationRunner {

	/**
	 * Run one tick of the active job.
	 *
	 * @return array<string,mixed> Public status.
	 */
	public static function rses_tick(): array {
		$rses_job = KeyGenerationJob::rses_get();
		if ( ! $rses_job ) {
			return array(
				'active'   => false,
				'stage'    => 'failed',
				'progress' => 0,
				'message'  => __( 'Key generation job not found.', 'relatasoft-secure-election-suite' ),
				'error'    => __( 'Key generation job not found.', 'relatasoft-secure-election-suite' ),
			);
		}

		$rses_stage = (string) ( $rses_job['stage'] ?? '' );

		if ( KeyGenerationJob::RSES_STAGE_COMPLETE === $rses_stage ) {
			return KeyGenerationJob::rses_public_status( $rses_job );
		}

		if ( KeyGenerationJob::RSES_STAGE_FAILED === $rses_stage ) {
			return KeyGenerationJob::rses_public_status( $rses_job );
		}

		if ( KeyGenerationJob::RSES_STAGE_CANCELLED === $rses_stage ) {
			return KeyGenerationJob::rses_public_status( $rses_job );
		}

		$rses_deadline = microtime( true ) + KeyGenerationJob::RSES_CHUNK_SECONDS;

		try {
			while ( microtime( true ) < $rses_deadline ) {
				// Honour cancel from a concurrent admin request (do not merge — local job is ahead).
				if ( self::rses_is_cancelled( (string) ( $rses_job['job_id'] ?? '' ) ) ) {
					return KeyGenerationJob::rses_public_status( KeyGenerationJob::rses_get() );
				}

				$rses_stage = (string) ( $rses_job['stage'] ?? '' );
				switch ( $rses_stage ) {
					case KeyGenerationJob::RSES_STAGE_SAFE_PRIME:
						$rses_job = self::rses_stage_safe_prime( $rses_job, $rses_deadline );
						KeyGenerationJob::rses_save( $rses_job );
						if ( KeyGenerationJob::RSES_STAGE_SAFE_PRIME === ( $rses_job['stage'] ?? '' ) ) {
							// Budget exhausted mid-search; yield to the browser.
							return KeyGenerationJob::rses_public_status( $rses_job );
						}
						break;

					case KeyGenerationJob::RSES_STAGE_GENERATOR:
						$rses_job = self::rses_stage_generator( $rses_job );
						KeyGenerationJob::rses_save( $rses_job );
						break;

					case KeyGenerationJob::RSES_STAGE_KEYPAIR:
						$rses_job = self::rses_stage_keypair( $rses_job );
						KeyGenerationJob::rses_save( $rses_job );
						break;

					case KeyGenerationJob::RSES_STAGE_PERSIST:
						$rses_job = self::rses_stage_persist( $rses_job );
						KeyGenerationJob::rses_save( $rses_job );
						break;

					case KeyGenerationJob::RSES_STAGE_VSS:
						$rses_job = self::rses_stage_vss( $rses_job );
						KeyGenerationJob::rses_save( $rses_job );
						return KeyGenerationJob::rses_public_status( $rses_job );

					case KeyGenerationJob::RSES_STAGE_COMPLETE:
						return KeyGenerationJob::rses_public_status( $rses_job );

					default:
						throw new \RuntimeException( __( 'Unknown key generation stage.', 'relatasoft-secure-election-suite' ) );
				}

				// After leaving safe-prime, yield once per stage so the UI can refresh.
				if ( KeyGenerationJob::RSES_STAGE_SAFE_PRIME !== $rses_stage ) {
					break;
				}
			}

			KeyGenerationJob::rses_save( $rses_job );
			return KeyGenerationJob::rses_public_status( $rses_job );
		} catch ( \Throwable $rses_e ) {
			$rses_job['stage']    = KeyGenerationJob::RSES_STAGE_FAILED;
			$rses_job['progress'] = 0;
			$rses_job['message']  = $rses_e->getMessage();
			$rses_job['error']    = $rses_e->getMessage();
			KeyGenerationJob::rses_clear_secrets( $rses_job );
			KeyGenerationJob::rses_save( $rses_job );

			AuditLogger::rses_log(
				'keygen_failed',
				'keygen_job',
				null,
				array(
					'job_id' => $rses_job['job_id'] ?? '',
					'error'  => $rses_e->getMessage(),
					'stage'  => $rses_stage,
				)
			);

			return KeyGenerationJob::rses_public_status( $rses_job );
		}
	}

	/**
	 * Whether the stored job was cancelled (or replaced).
	 *
	 * @param string $job_id Job id.
	 * @return bool
	 */
	private static function rses_is_cancelled( string $job_id ): bool {
		// Read option directly to avoid purge side-effects mid-tick.
		$rses_stored = get_option( KeyGenerationJob::RSES_OPTION_KEY, null );
		if ( ! is_array( $rses_stored ) ) {
			return true;
		}
		if ( ( $rses_stored['job_id'] ?? '' ) !== $job_id ) {
			return true;
		}
		return KeyGenerationJob::RSES_STAGE_CANCELLED === ( $rses_stored['stage'] ?? '' );
	}

	/**
	 * Checkpointed safe-prime search (deterministic attempt stream).
	 *
	 * @param array<string,mixed> $job      Job.
	 * @param float               $deadline Wall-clock deadline.
	 * @return array<string,mixed>
	 */
	private static function rses_stage_safe_prime( array $job, float $deadline ): array {
		$rses_bits    = (int) $job['bits'];
		$rses_attempt = (int) ( $job['safe_prime_attempt'] ?? 0 );
		$rses_rng     = DeterministicRandom::fromHex( (string) $job['seed_hex'] );
		$rses_q_bits  = $rses_bits - 1;

		while ( microtime( true ) < $deadline ) {
			// Attempt index is 0-based; checkpoint stores the next index to try.
			$rses_q = $rses_rng->oddIntegerOfBitLengthForAttempt( $rses_attempt, $rses_q_bits );
			++$rses_attempt;

			$job['safe_prime_attempt'] = $rses_attempt;
			$job['attempts_done']      = $rses_attempt;
			$job['message']            = sprintf(
				/* translators: %d: attempt number */
				__( 'Searching for a safe prime (attempt %d)…', 'relatasoft-secure-election-suite' ),
				$rses_attempt
			);
			$job['progress'] = min( 50, 5 + (int) floor( log( max( 1, $rses_attempt ), 2 ) ) );

			$rses_found = PrimeGenerator::trySafePrimeFromQ( $rses_q, $rses_bits );
			if ( null === $rses_found ) {
				continue;
			}

			list( $rses_p, $rses_q_prime ) = $rses_found;
			$job['public_p'] = BigInt::toDecimalString( $rses_p );
			$job['public_q'] = BigInt::toDecimalString( $rses_q_prime );
			$job['stage']    = KeyGenerationJob::RSES_STAGE_GENERATOR;
			$job['message']  = __( 'Safe prime found. Selecting generator…', 'relatasoft-secure-election-suite' );
			$job['progress'] = 55;
			return $job;
		}

		return $job;
	}

	/**
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed>
	 */
	private static function rses_stage_generator( array $job ): array {
		$rses_p = \gmp_init( (string) $job['public_p'], 10 );
		$rses_q = \gmp_init( (string) $job['public_q'], 10 );
		$rses_g = PrimeGenerator::findGeneratorForSafePrime( $rses_p, $rses_q );

		$job['public_g'] = BigInt::toDecimalString( $rses_g );
		$job['stage']    = KeyGenerationJob::RSES_STAGE_KEYPAIR;
		$job['message']  = __( 'Generator selected. Creating key pair…', 'relatasoft-secure-election-suite' );
		$job['progress'] = 65;
		return $job;
	}

	/**
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed>
	 */
	private static function rses_stage_keypair( array $job ): array {
		$rses_p    = \gmp_init( (string) $job['public_p'], 10 );
		$rses_q    = \gmp_init( (string) $job['public_q'], 10 );
		$rses_g    = \gmp_init( (string) $job['public_g'], 10 );
		$rses_bits = (int) $job['bits'];

		$rses_kp = ElGamal::generateKeyPairFromParams( $rses_p, $rses_q, $rses_g, $rses_bits );

		$job['public_y']             = $rses_kp->getY();
		$job['private_x_encrypted']  = ShareEncryptionService::rses_encrypt( $rses_kp->getX() );
		$job['stage']                = KeyGenerationJob::RSES_STAGE_PERSIST;
		$job['message']              = __( 'Key pair created. Saving public parameters…', 'relatasoft-secure-election-suite' );
		$job['progress']             = 75;
		return $job;
	}

	/**
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed>
	 */
	private static function rses_stage_persist( array $job ): array {
		$rses_attachments = array();
		if ( ! empty( $job['attachment_id'] ) ) {
			$rses_attachments[] = (int) $job['attachment_id'];
		}

		$rses_key_id = KeyRepository::rses_create(
			array(
				'election_round_id'     => ! empty( $job['round_id'] ) ? (int) $job['round_id'] : null,
				'key_label'             => (string) $job['label'],
				'public_p'              => (string) $job['public_p'],
				'public_q'              => (string) $job['public_q'],
				'public_g'              => (string) $job['public_g'],
				'public_y'              => (string) $job['public_y'],
				'key_size'              => (int) $job['bits'],
				'private_key_persisted' => 0,
				'description'           => (string) ( $job['description'] ?? '' ),
				'attachments'           => $rses_attachments,
				'threshold_t'           => (int) $job['threshold_t'],
				'total_n'               => (int) $job['total_n'],
			)
		);

		$job['key_id']   = $rses_key_id;
		$job['stage']    = KeyGenerationJob::RSES_STAGE_VSS;
		$job['message']  = __( 'Public parameters saved. Splitting trustee shares…', 'relatasoft-secure-election-suite' );
		$job['progress'] = 85;
		return $job;
	}

	/**
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed>
	 */
	private static function rses_stage_vss( array $job ): array {
		$rses_key_id    = (int) $job['key_id'];
		$rses_threshold = (int) $job['threshold_t'];
		$rses_total     = (int) $job['total_n'];
		$rses_officials = array_values( array_map( 'intval', $job['officials'] ?? array() ) );
		$rses_round_id  = (int) ( $job['round_id'] ?? 0 );

		$rses_x_plain = ShareEncryptionService::rses_decrypt( (string) $job['private_x_encrypted'] );

		$rses_keypair = new ElGamalKeyPair(
			(string) $job['public_p'],
			(string) $job['public_q'],
			(string) $job['public_g'],
			$rses_x_plain,
			(string) $job['public_y'],
			(int) $job['bits'],
			gmdate( 'Y-m-d H:i:s' )
		);

		if ( $rses_threshold >= 2 && $rses_total >= $rses_threshold && count( $rses_officials ) === $rses_total ) {
			ShareAssignmentService::rses_assign_shares(
				$rses_keypair,
				$rses_key_id,
				$rses_round_id,
				$rses_threshold,
				$rses_total,
				$rses_officials
			);
			$job['message'] = __( 'Key generation complete. Feldman VSS shares assigned to officials.', 'relatasoft-secure-election-suite' );
		} else {
			$job['message'] = __( 'Key generation complete. No officials selected — public key saved without Feldman VSS shares.', 'relatasoft-secure-election-suite' );
		}

		KeyGenerationJob::rses_clear_secrets( $job );
		$job['stage']    = KeyGenerationJob::RSES_STAGE_COMPLETE;
		$job['progress'] = 100;
		$job['error']    = null;

		AuditLogger::rses_log(
			'key_generate',
			'key',
			$rses_key_id,
			array(
				'bits'      => (int) $job['bits'],
				'threshold' => $rses_threshold,
				'n'         => $rses_total,
				'job_id'    => (string) ( $job['job_id'] ?? '' ),
				'attempts'  => (int) ( $job['safe_prime_attempt'] ?? 0 ),
				'chunked'   => true,
				'scheme_id' => \RelataSoft\SecureElectionSuite\Crypto\CryptoSchemeRegistry::rses_active_generation_scheme(),
			)
		);

		return $job;
	}
}
