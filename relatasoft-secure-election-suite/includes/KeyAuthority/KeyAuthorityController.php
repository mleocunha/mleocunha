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
use RelataSoft\SecureElectionSuite\Crypto\ElGamal;
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
		add_action( 'admin_post_rses_generate_key', array( self::class, 'rses_handle_generate_key' ) );
		add_action( 'admin_post_rses_import_key', array( self::class, 'rses_handle_import_key' ) );
		add_action( 'admin_post_rses_export_key', array( self::class, 'rses_handle_export_key' ) );
		add_action( 'admin_post_rses_key_action', array( self::class, 'rses_handle_key_action' ) );
	}

	/**
	 * Handle key generation.
	 */
	public static function rses_handle_generate_key(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_KEY_GENERATE );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_KEY_AUTHORITY );

		if ( ! BigInt::rses_gmp_available() ) {
			wp_die( esc_html__( 'GMP extension required.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_bits       = Sanitizer::rses_id( $_POST['rses_key_size'] ?? 2048 );
		$rses_label      = Sanitizer::rses_post_text( 'rses_key_label' );
		$rses_desc       = Sanitizer::rses_textarea( $_POST['rses_key_description'] ?? '' );
		$rses_threshold  = Sanitizer::rses_id( $_POST['rses_threshold_t'] ?? 3 );
		$rses_total      = Sanitizer::rses_id( $_POST['rses_total_n'] ?? 5 );
		$rses_officials  = isset( $_POST['rses_officials'] ) && is_array( $_POST['rses_officials'] )
			? array_map( 'absint', wp_unslash( $_POST['rses_officials'] ) )
			: array();
		$rses_round_id   = Sanitizer::rses_post_id( 'rses_election_round_id' );
		$rses_attachment = Sanitizer::rses_post_id( 'rses_attachment_id' );

		if ( $rses_bits < 512 ) {
			$rses_bits = 512;
		}

		try {
			$rses_keypair = ElGamal::generateKeyPair( $rses_bits );

			$rses_attachments = $rses_attachment > 0 ? array( $rses_attachment ) : array();

			$rses_key_id = KeyRepository::rses_create(
				array(
					'key_label'             => $rses_label ?: __( 'ElGamal Key', 'relatasoft-secure-election-suite' ),
					'public_p'              => $rses_keypair->getP(),
					'public_q'              => $rses_keypair->getQ(),
					'public_g'              => $rses_keypair->getG(),
					'public_y'              => $rses_keypair->getY(),
					'key_size'              => $rses_bits,
					'description'           => $rses_desc,
					'attachments'           => $rses_attachments,
					'election_round_id'     => $rses_round_id ?: null,
					'private_key_persisted' => 0,
				)
			);

			if ( $rses_key_id && $rses_threshold >= 2 && $rses_total >= $rses_threshold && ! empty( $rses_officials ) ) {
				ShareAssignmentService::rses_assign_shares(
					$rses_keypair,
					$rses_key_id,
					$rses_round_id,
					$rses_threshold,
					$rses_total,
					$rses_officials
				);
			}

			AuditLogger::rses_log( 'key_generate', 'key', $rses_key_id, array(
				'key_size'  => $rses_bits,
				'threshold' => $rses_threshold,
				'total'     => $rses_total,
			) );

			wp_safe_redirect( admin_url( 'admin.php?page=rses-key-authority&rses_key_created=' . $rses_key_id ) );
			exit;
		} catch ( CryptoException $rses_e ) {
			wp_die( esc_html( $rses_e->getMessage() ) );
		}
	}

	/**
	 * Handle key import.
	 */
	public static function rses_handle_import_key(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_KEY_IMPORT );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_KEY_AUTHORITY );

		$rses_label = Sanitizer::rses_post_text( 'rses_import_label' );
		$rses_json  = isset( $_POST['rses_import_json'] )
			? wp_unslash( $_POST['rses_import_json'] )
			: '';

		$rses_data = Sanitizer::rses_json( $rses_json );

		if ( null === $rses_data ) {
			wp_die( esc_html__( 'Invalid JSON import data.', 'relatasoft-secure-election-suite' ) );
		}

		try {
			$rses_key_id = KeyImportService::rses_import_public_key( $rses_data, $rses_label );
			wp_safe_redirect( admin_url( 'admin.php?page=rses-key-authority&rses_key_imported=' . $rses_key_id ) );
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
			Capability::rses_require_admin();
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
