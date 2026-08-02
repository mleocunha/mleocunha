<?php
/**
 * Chunked, checkpointed key-generation job storage.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

defined( 'ABSPATH' ) || exit;

/**
 * Single-site key generation job (one at a time).
 */
class KeyGenerationJob {

	public const RSES_OPTION_KEY     = 'rses_keygen_job';
	public const RSES_CHUNK_SECONDS  = 25.0;
	public const RSES_TTL_SECONDS    = 86400; // 24 hours.

	public const RSES_STAGE_SAFE_PRIME   = 'safe_prime';
	public const RSES_STAGE_GENERATOR    = 'generator';
	public const RSES_STAGE_KEYPAIR      = 'keypair';
	public const RSES_STAGE_PERSIST      = 'persist';
	public const RSES_STAGE_SHAMIR       = 'shamir';
	public const RSES_STAGE_COMPLETE     = 'complete';
	public const RSES_STAGE_CANCELLED    = 'cancelled';
	public const RSES_STAGE_FAILED       = 'failed';

	/**
	 * Active statuses that block a new job.
	 *
	 * @var array<int,string>
	 */
	private static array $rses_active_stages = array(
		self::RSES_STAGE_SAFE_PRIME,
		self::RSES_STAGE_GENERATOR,
		self::RSES_STAGE_KEYPAIR,
		self::RSES_STAGE_PERSIST,
		self::RSES_STAGE_SHAMIR,
	);

	/**
	 * Load job, purging if expired.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function rses_get(): ?array {
		self::rses_purge_if_expired();

		$rses_job = get_option( self::RSES_OPTION_KEY, null );
		return is_array( $rses_job ) ? $rses_job : null;
	}

	/**
	 * Whether an active (non-terminal) job exists.
	 *
	 * @return bool
	 */
	public static function rses_has_active(): bool {
		$rses_job = self::rses_get();
		if ( ! $rses_job ) {
			return false;
		}
		return in_array( $rses_job['stage'] ?? '', self::$rses_active_stages, true );
	}

	/**
	 * Persist job state.
	 *
	 * @param array<string,mixed> $job Job.
	 */
	public static function rses_save( array $job ): void {
		$job['updated_at'] = time();
		update_option( self::RSES_OPTION_KEY, $job, false );
	}

	/**
	 * Delete job.
	 */
	public static function rses_delete(): void {
		delete_option( self::RSES_OPTION_KEY );
	}

	/**
	 * Purge job if older than TTL.
	 *
	 * @return bool True if purged.
	 */
	public static function rses_purge_if_expired(): bool {
		$rses_job = get_option( self::RSES_OPTION_KEY, null );
		if ( ! is_array( $rses_job ) ) {
			return false;
		}

		$rses_updated = (int) ( $rses_job['updated_at'] ?? $rses_job['created_at'] ?? 0 );
		if ( $rses_updated > 0 && ( time() - $rses_updated ) > self::RSES_TTL_SECONDS ) {
			self::rses_clear_secrets( $rses_job );
			delete_option( self::RSES_OPTION_KEY );
			return true;
		}

		return false;
	}

	/**
	 * Create a new job.
	 *
	 * @param array<string,mixed> $params Job parameters.
	 * @return array<string,mixed>
	 */
	public static function rses_create( array $params ): array {
		$rses_seed = \RelataSoft\SecureElectionSuite\Crypto\CryptoRandom::randomBytes( 32 );

		$rses_job = array(
			'job_id'           => bin2hex( \RelataSoft\SecureElectionSuite\Crypto\CryptoRandom::randomBytes( 8 ) ),
			'stage'            => self::RSES_STAGE_SAFE_PRIME,
			'progress'         => 1,
			'message'          => __( 'Starting safe prime search…', 'relatasoft-secure-election-suite' ),
			'seed_hex'         => bin2hex( $rses_seed ),
			'bits'             => (int) $params['bits'],
			'label'            => (string) $params['label'],
			'description'      => (string) ( $params['description'] ?? '' ),
			'threshold_t'      => (int) $params['threshold_t'],
			'total_n'          => (int) $params['total_n'],
			'officials'        => array_values( array_map( 'intval', $params['officials'] ?? array() ) ),
			'round_id'         => (int) ( $params['round_id'] ?? 0 ),
			'attachment_id'    => (int) ( $params['attachment_id'] ?? 0 ),
			'safe_prime_attempt' => 0,
			'generator_attempt'  => 0,
			'created_at'       => time(),
			'updated_at'       => time(),
			'created_by'       => get_current_user_id(),
			'public_p'         => null,
			'public_q'         => null,
			'public_g'         => null,
			'public_y'         => null,
			'private_x_encrypted' => null,
			'field_prime'      => null,
			'key_id'           => null,
			'error'            => null,
			'attempts_done'    => 0,
		);

		self::rses_save( $rses_job );
		return $rses_job;
	}

	/**
	 * Public status payload for AJAX (never includes private key material).
	 *
	 * @param array<string,mixed>|null $job Job.
	 * @return array<string,mixed>
	 */
	public static function rses_public_status( ?array $job ): array {
		if ( ! $job ) {
			return array(
				'active'   => false,
				'stage'    => null,
				'progress' => 0,
				'message'  => '',
			);
		}

		return array(
			'active'        => in_array( $job['stage'] ?? '', self::$rses_active_stages, true ),
			'job_id'        => $job['job_id'] ?? '',
			'stage'         => $job['stage'] ?? '',
			'progress'      => (int) ( $job['progress'] ?? 0 ),
			'message'       => (string) ( $job['message'] ?? '' ),
			'bits'          => (int) ( $job['bits'] ?? 0 ),
			'attempts_done' => (int) ( $job['attempts_done'] ?? 0 ),
			'key_id'        => $job['key_id'] ?? null,
			'error'         => $job['error'] ?? null,
			'label'         => $job['label'] ?? '',
			'updated_at'    => (int) ( $job['updated_at'] ?? 0 ),
			'created_at'    => (int) ( $job['created_at'] ?? 0 ),
		);
	}

	/**
	 * Clear sensitive fields from a job array (in place).
	 *
	 * @param array<string,mixed> $job Job.
	 */
	public static function rses_clear_secrets( array &$job ): void {
		$job['private_x_encrypted'] = null;
		$job['seed_hex']            = '';
	}

	/**
	 * Cancel active job.
	 *
	 * @return bool
	 */
	public static function rses_cancel(): bool {
		$rses_job = self::rses_get();
		if ( ! $rses_job || ! self::rses_has_active() ) {
			self::rses_delete();
			return true;
		}

		$rses_job['stage']    = self::RSES_STAGE_CANCELLED;
		$rses_job['progress'] = 0;
		$rses_job['message']  = __( 'Key generation cancelled.', 'relatasoft-secure-election-suite' );
		self::rses_clear_secrets( $rses_job );
		self::rses_save( $rses_job );
		return true;
	}
}
