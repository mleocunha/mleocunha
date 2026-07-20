<?php
/**
 * Key Authority controller.
 *
 * @package RelataSoft\SecureElectionSuite\KeyAuthority
 */

namespace RelataSoft\SecureElectionSuite\KeyAuthority;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Crypto\CryptoException;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\Security\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Handles Key Authority admin-post actions.
 */
class KeyAuthorityController {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_generate_key', array( self::class, 'rses_handle_generate_key_legacy' ) );
		add_action( 'admin_post_rses_import_key', array( self::class, 'rses_handle_import_key' ) );
		add_action( 'admin_post_rses_export_key', array( self::class, 'rses_handle_export_key' ) );
		add_action( 'admin_post_rses_key_action', array( self::class, 'rses_handle_key_action' ) );

		add_action( 'wp_ajax_rses_keygen_start', array( self::class, 'rses_ajax_keygen_start' ) );
		add_action( 'wp_ajax_rses_keygen_tick', array( self::class, 'rses_ajax_keygen_tick' ) );
		add_action( 'wp_ajax_rses_keygen_status', array( self::class, 'rses_ajax_keygen_status' ) );
		add_action( 'wp_ajax_rses_keygen_cancel', array( self::class, 'rses_ajax_keygen_cancel' ) );

		add_action( 'admin_init', array( KeyGenerationJob::class, 'rses_purge_if_expired' ) );
	}

	/**
	 * Legacy synchronous generate — redirects users to chunked UI.
	 */
	public static function rses_handle_generate_key_legacy(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_KEY_GENERATE );
		wp_safe_redirect( admin_url( 'admin.php?page=rses-key-authority&rses_use_chunked=1' ) );
		exit;
	}

	/**
	 * AJAX: start chunked key generation.
	 */
	public static function rses_ajax_keygen_start(): void {
		self::rses_ajax_guard();

		if ( ! BigInt::rses_gmp_available() ) {
			wp_send_json_error( array( 'message' => __( 'GMP extension required.', 'relatasoft-secure-election-suite' ) ), 500 );
		}

		KeyGenerationJob::rses_purge_if_expired();

		if ( KeyGenerationJob::rses_has_active() ) {
			wp_send_json_error(
				array(
					'message' => __( 'A key generation job is already running. Cancel it first or wait for completion.', 'relatasoft-secure-election-suite' ),
					'status'  => KeyGenerationJob::rses_public_status( KeyGenerationJob::rses_get() ),
				),
				409
			);
		}

		$rses_bits       = Sanitizer::rses_id( $_POST['rses_key_size'] ?? 2048 );
		$rses_label      = Sanitizer::rses_post_text( 'rses_key_label' );
		$rses_desc       = Sanitizer::rses_textarea( $_POST['rses_key_description'] ?? '' );
		$rses_threshold  = Sanitizer::rses_id( $_POST['rses_threshold_t'] ?? 3 );
		$rses_officials  = isset( $_POST['rses_officials'] ) && is_array( $_POST['rses_officials'] )
			? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['rses_officials'] ) ) ) ) )
			: array();
		$rses_round_id   = Sanitizer::rses_post_id( 'rses_election_round_id' );
		$rses_attachment = Sanitizer::rses_post_id( 'rses_attachment_id' );

		$rses_allowed_bits = array( 512, 1024, 2048, 3072, 4096 );
		if ( ! in_array( $rses_bits, $rses_allowed_bits, true ) ) {
			$rses_bits = 2048;
		}

		$rses_total = max( 2, count( $rses_officials ) ?: Sanitizer::rses_id( $_POST['rses_total_n'] ?? 5 ) );
		if ( ! empty( $rses_officials ) ) {
			$rses_total = count( $rses_officials );
			if ( $rses_threshold > $rses_total ) {
				$rses_threshold = $rses_total;
			}
			if ( $rses_threshold < 2 && $rses_total >= 2 ) {
				$rses_threshold = 2;
			}
		}

		// Clear terminal leftover job.
		KeyGenerationJob::rses_delete();

		$rses_job = KeyGenerationJob::rses_create(
			array(
				'bits'          => $rses_bits,
				'label'         => $rses_label ?: __( 'ElGamal Key', 'relatasoft-secure-election-suite' ),
				'description'   => $rses_desc,
				'threshold_t'   => $rses_threshold,
				'total_n'       => $rses_total,
				'officials'     => $rses_officials,
				'round_id'      => $rses_round_id,
				'attachment_id' => $rses_attachment,
			)
		);

		AuditLogger::rses_log(
			'keygen_start',
			'keygen_job',
			null,
			array(
				'job_id' => $rses_job['job_id'],
				'bits'   => $rses_bits,
			)
		);

		// Run first chunk immediately.
		$rses_status = KeyGenerationRunner::rses_tick();
		wp_send_json_success( $rses_status );
	}

	/**
	 * AJAX: continue chunked generation.
	 */
	public static function rses_ajax_keygen_tick(): void {
		self::rses_ajax_guard();
		$rses_status = KeyGenerationRunner::rses_tick();
		wp_send_json_success( $rses_status );
	}

	/**
	 * AJAX: job status.
	 */
	public static function rses_ajax_keygen_status(): void {
		self::rses_ajax_guard();
		wp_send_json_success( KeyGenerationJob::rses_public_status( KeyGenerationJob::rses_get() ) );
	}

	/**
	 * AJAX: cancel job.
	 */
	public static function rses_ajax_keygen_cancel(): void {
		self::rses_ajax_guard();
		KeyGenerationJob::rses_cancel();
		AuditLogger::rses_log( 'keygen_cancel', 'keygen_job', null, array() );
		wp_send_json_success( KeyGenerationJob::rses_public_status( KeyGenerationJob::rses_get() ) );
	}

	/**
	 * Shared AJAX auth/nonce/mode checks.
	 */
	private static function rses_ajax_guard(): void {
		if ( ! Capability::rses_can_manage_election() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'relatasoft-secure-election-suite' ) ), 403 );
		}
		check_ajax_referer( 'rses_keygen', 'nonce' );
		if ( ! ModeLock::rses_is_mode( ModeLock::RSES_MODE_KEY_AUTHORITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Not available in this mode.', 'relatasoft-secure-election-suite' ) ), 403 );
		}
	}

	/**
	 * Handle key generation (removed — use AJAX chunked flow).
	 *
	 * @deprecated
	 */
	public static function rses_handle_generate_key(): void {
		self::rses_handle_generate_key_legacy();
	}

	/**
	 * Handle key import (Key Authority or Voting public-key import).
	 */
	public static function rses_handle_import_key(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_KEY_IMPORT );

		$rses_mode = ModeLock::rses_get_mode();
		if ( ! in_array( $rses_mode, array( ModeLock::RSES_MODE_KEY_AUTHORITY, ModeLock::RSES_MODE_VOTING ), true ) ) {
			wp_die( esc_html__( 'This action is not available in the current plugin mode.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_label = Sanitizer::rses_post_text( 'rses_import_label' );
		$rses_json  = isset( $_POST['rses_import_json'] )
			? wp_unslash( $_POST['rses_import_json'] )
			: '';

		// Allow wrapping from public-key.json export that may include keySizeBits only.
		$rses_data = Sanitizer::rses_json( $rses_json );

		if ( null === $rses_data ) {
			wp_die( esc_html__( 'Invalid JSON import data.', 'relatasoft-secure-election-suite' ) );
		}

		// Accept nested public_key object from fuller export packages.
		if ( isset( $rses_data['public_key'] ) && is_array( $rses_data['public_key'] ) ) {
			$rses_data = $rses_data['public_key'];
		}

		try {
			$rses_key_id = KeyImportService::rses_import_public_key( $rses_data, $rses_label ?: __( 'Imported Public Key', 'relatasoft-secure-election-suite' ) );

			$rses_redirect = ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING )
				? 'admin.php?page=rses-public-keys&rses_key_imported=' . $rses_key_id
				: 'admin.php?page=rses-key-authority&rses_key_imported=' . $rses_key_id;

			wp_safe_redirect( admin_url( $rses_redirect ) );
			exit;
		} catch ( CryptoException $rses_e ) {
			wp_die( esc_html( $rses_e->getMessage() ) );
		}
	}

	/**
	 * Handle key export.
	 */
	public static function rses_handle_export_key(): void {
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_KEY_EXPORT );

		$rses_key_id    = Sanitizer::rses_id( $_GET['key_id'] ?? 0 );
		$rses_format    = Sanitizer::rses_text( $_GET['format'] ?? 'zip' );
		$rses_full      = ! empty( $_GET['full'] );
		$rses_own_share = ! empty( $_GET['own_share'] );

		if ( $rses_full ) {
			Capability::rses_require_admin();
			if ( ! Capability::rses_can_export_all_shares() ) {
				wp_die( esc_html__( 'Full private export is disabled.', 'relatasoft-secure-election-suite' ) );
			}
			if ( empty( $_GET['rses_confirm_full'] ) ) {
				wp_die( esc_html__( 'Full export requires explicit confirmation.', 'relatasoft-secure-election-suite' ) );
			}
		} elseif ( $rses_own_share ) {
			Capability::rses_require_official();
		} else {
			// Public key export: admin or official.
			Capability::rses_require_official();
		}

		ModeLock::rses_require_mode( ModeLock::RSES_MODE_KEY_AUTHORITY );

		if ( 'json' === $rses_format ) {
			KeyExportService::rses_export_public_json( $rses_key_id );
		} else {
			KeyExportService::rses_export_zip( $rses_key_id, $rses_full, $rses_own_share );
		}
	}

	/**
	 * Handle trash/restore/delete actions.
	 */
	public static function rses_handle_key_action(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_KEY_EXPORT );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_KEY_AUTHORITY );

		$rses_key_id  = Sanitizer::rses_id( $_POST['key_id'] ?? 0 );
		$rses_action  = Sanitizer::rses_text( $_POST['rses_key_action'] ?? '' );

		switch ( $rses_action ) {
			case 'trash':
				KeyRepository::rses_trash( $rses_key_id );
				AuditLogger::rses_log( 'key_trash', 'key', $rses_key_id );
				break;
			case 'restore':
				KeyRepository::rses_restore( $rses_key_id );
				AuditLogger::rses_log( 'key_restore', 'key', $rses_key_id );
				break;
			case 'delete':
				KeyRepository::rses_delete( $rses_key_id );
				AuditLogger::rses_log( 'key_delete', 'key', $rses_key_id );
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=rses-key-authority' ) );
		exit;
	}
}
