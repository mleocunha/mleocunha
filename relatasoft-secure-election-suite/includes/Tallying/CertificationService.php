<?php
/**
 * Certification service.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Database\Repository;
use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Election certification and report generation.
 */
class CertificationService {

	/**
	 * Register handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_certify', array( self::class, 'rses_handle_certify' ) );
		add_action( 'admin_post_rses_export_certification', array( self::class, 'rses_handle_export' ) );
	}

	/**
	 * Handle certification (packages admin attestation around already-signed artifacts).
	 */
	public static function rses_handle_certify(): void {
		Capability::rses_require_tally_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_CERTIFICATION );

		$rses_import_id = absint( $_POST['tally_import_id'] ?? 0 );
		$rses_result     = get_transient( 'rses_decryption_result_' . $rses_import_id );

		if ( ! is_array( $rses_result ) ) {
			$rses_signed = SignedResultsService::rses_get_package( $rses_import_id );
			if ( is_array( $rses_signed ) && is_array( $rses_signed['results'] ?? null ) ) {
				$rses_result = array(
					'decrypted_results' => $rses_signed['results']['decrypted_results'] ?? array(),
					'threshold'         => $rses_signed['results']['threshold'] ?? 0,
					'signed_package'    => $rses_signed,
				);
			}
		}

		if ( ! is_array( $rses_result ) ) {
			wp_die( esc_html__( 'No decryption results available. Run decryption first.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_import   = TallyImportRepository::rses_get( $rses_import_id );
		$rses_manifest = TallyImportRepository::rses_get_manifest( $rses_import );

		$rses_encrypted_hash  = HashService::rses_hash_json( $rses_manifest['encrypted_tallies'] ?? array() );
		$rses_decrypted_hash  = HashService::rses_hash_json( $rses_result['decrypted_results'] );
		$rses_public_key_hash = HashService::rses_hash_json( $rses_manifest['public_key'] ?? array() );

		$rses_submissions = OfficialShareSubmissionController::rses_get_submissions( $rses_import_id );
		$rses_officials   = array();

		foreach ( $rses_submissions as $rses_sub ) {
			$rses_user = get_userdata( (int) $rses_sub->official_user_id );
			$rses_officials[] = array(
				'user_id'      => (int) $rses_sub->official_user_id,
				'display_name' => $rses_user ? $rses_user->display_name : '',
				'share_index'  => (int) $rses_sub->share_index,
				'submitted_at' => $rses_sub->submitted_at,
			);
		}

		$rses_ballot    = is_array( $rses_manifest['ballot'] ?? null ) ? $rses_manifest['ballot'] : array();
		$rses_signed    = SignedResultsService::rses_get_package( $rses_import_id )
			?? ( is_array( $rses_result['signed_package'] ?? null ) ? $rses_result['signed_package'] : null );
		$rses_humanized = null;
		if ( is_array( $rses_signed ) && is_array( $rses_signed['results']['humanized_results'] ?? null ) ) {
			$rses_humanized = $rses_signed['results']['humanized_results'];
		} else {
			$rses_humanized = DecryptedResultsPresenter::rses_humanize(
				is_array( $rses_result['decrypted_results'] ?? null ) ? $rses_result['decrypted_results'] : array(),
				$rses_ballot,
				DecryptedResultsPresenter::RSES_SORT_COUNT_DESC
			);
		}

		$rses_report = array(
			'election_title'           => $rses_manifest['election']['title'] ?? TallyImportRepository::rses_display_election_title( $rses_import ),
			'round_title'              => $rses_manifest['round']['title'] ?? TallyImportRepository::rses_display_round_title( $rses_import ),
			'round_number'             => $rses_manifest['round']['round_number'] ?? 1,
			'source_imports'           => array( $rses_import_id ),
			'source_site_urls'         => array( $rses_import->source_site_url ),
			'manifest_hash'            => $rses_import->import_hash,
			'public_key_hash'          => $rses_public_key_hash,
			'public_key_fingerprint'   => is_array( $rses_signed )
				? (string) ( $rses_signed['public_key_fingerprint'] ?? '' )
				: TallyImportRepository::rses_public_key_fingerprint( is_array( $rses_manifest['public_key'] ?? null ) ? $rses_manifest['public_key'] : array() ),
			'encrypted_sum_hash'       => $rses_encrypted_hash,
			'decrypted_result_hash'    => $rses_decrypted_hash,
			'ballot_count'             => $rses_manifest['manifest']['ballot_count'] ?? 0,
			'threshold'                => $rses_result['threshold'] ?? 0,
			'officials_submitted'      => $rses_officials,
			'verification_status'      => 'certified',
			'certified_at'             => gmdate( 'c' ),
			'certified_by'             => get_current_user_id(),
			'ballot'                   => $rses_ballot,
			'decrypted_results'        => $rses_result['decrypted_results'],
			'humanized_results'        => $rses_humanized,
			'signed_results'           => $rses_signed,
			'signature_scheme'         => is_array( $rses_signed ) ? (string) ( $rses_signed['scheme'] ?? '' ) : '',
		);

		$rses_atts = SignedResultsService::rses_get_attachment_ids( $rses_import_id );

		$rses_row = array(
			'tally_import_id'          => $rses_import_id,
			'certification_status'     => 'certified',
			'encrypted_sum_hash'       => $rses_encrypted_hash,
			'decrypted_result_hash'    => $rses_decrypted_hash,
			'verification_report_json' => wp_json_encode( $rses_report ),
			'certified_by'             => get_current_user_id(),
			'certified_at'             => current_time( 'mysql', true ),
			'audit_hash'               => HashService::rses_hash_json( $rses_report ),
		);
		$rses_fmt = array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
		if ( $rses_atts['pdf_attachment_id'] > 0 ) {
			$rses_row['pdf_attachment_id'] = $rses_atts['pdf_attachment_id'];
			$rses_fmt[]                    = '%d';
		}

		$rses_cert_id = Repository::rses_insert( 'rses_certifications', $rses_row, $rses_fmt );

		set_transient( 'rses_certification_' . $rses_import_id, $rses_report, DAY_IN_SECONDS );

		AuditLogger::rses_log( 'certification', 'certification', $rses_cert_id );

		wp_safe_redirect( admin_url( 'admin.php?page=rses-certification&rses_certified=' . $rses_cert_id ) );
		exit;
	}

	/**
	 * Export certification package.
	 */
	public static function rses_handle_export(): void {
		Capability::rses_require_tally_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_CERTIFICATION );

		$rses_import_id = absint( $_GET['import_id'] ?? 0 );
		$rses_format    = sanitize_text_field( wp_unslash( $_GET['format'] ?? 'zip' ) );

		$rses_report = get_transient( 'rses_certification_' . $rses_import_id );
		if ( ! is_array( $rses_report ) ) {
			$rses_report = self::rses_load_report_from_db( $rses_import_id );
		}
		if ( ! is_array( $rses_report ) ) {
			$rses_report = self::rses_report_from_decryption( $rses_import_id );
		}

		if ( ! is_array( $rses_report ) ) {
			wp_die( esc_html__( 'Certification report not found. Decrypt the tally (and preferably generate certification) first.', 'relatasoft-secure-election-suite' ) );
		}

		if ( 'pdf' === $rses_format ) {
			CertificationReportService::rses_export_pdf( $rses_report, $rses_import_id );
		}

		CertificationReportService::rses_export_zip( $rses_report, $rses_import_id );
	}

	/**
	 * Load the latest certification report JSON from the database.
	 *
	 * @param int $import_id Import ID.
	 * @return array<string,mixed>|null
	 */
	private static function rses_load_report_from_db( int $import_id ): ?array {
		$rses_rows = Repository::rses_get_rows(
			'rses_certifications',
			'tally_import_id = %d',
			array( $import_id ),
			'id DESC',
			1
		);
		if ( empty( $rses_rows[0] ) ) {
			return null;
		}
		$rses_json = (string) ( $rses_rows[0]->verification_report_json ?? '' );
		$rses_data = json_decode( $rses_json, true );
		return is_array( $rses_data ) ? $rses_data : null;
	}

	/**
	 * Build a provisional report from a decryption transient / signed package.
	 *
	 * @param int $import_id Import ID.
	 * @return array<string,mixed>|null
	 */
	private static function rses_report_from_decryption( int $import_id ): ?array {
		$rses_result = get_transient( 'rses_decryption_result_' . $import_id );
		$rses_import = TallyImportRepository::rses_get( $import_id );
		$rses_signed = SignedResultsService::rses_get_package( $import_id );

		if ( ! $rses_import ) {
			return null;
		}

		if ( ! is_array( $rses_result ) && is_array( $rses_signed ) ) {
			$rses_result = array(
				'decrypted_results' => $rses_signed['results']['decrypted_results'] ?? array(),
				'threshold'         => $rses_signed['results']['threshold'] ?? 0,
			);
		}

		if ( ! is_array( $rses_result ) ) {
			return null;
		}

		$rses_manifest = TallyImportRepository::rses_get_manifest( $rses_import );
		$rses_ballot   = is_array( $rses_manifest['ballot'] ?? null ) ? $rses_manifest['ballot'] : array();
		$rses_raw      = is_array( $rses_result['decrypted_results'] ?? null ) ? $rses_result['decrypted_results'] : array();
		$rses_humanized = is_array( $rses_signed['results']['humanized_results'] ?? null )
			? $rses_signed['results']['humanized_results']
			: DecryptedResultsPresenter::rses_humanize(
				$rses_raw,
				$rses_ballot,
				DecryptedResultsPresenter::RSES_SORT_COUNT_DESC
			);

		return array(
			'election_title'         => TallyImportRepository::rses_display_election_title( $rses_import ),
			'round_title'            => TallyImportRepository::rses_display_round_title( $rses_import ),
			'round_number'           => $rses_manifest['round']['round_number'] ?? 1,
			'public_key_hash'        => HashService::rses_hash_json( $rses_manifest['public_key'] ?? array() ),
			'public_key_fingerprint' => is_array( $rses_signed )
				? (string) ( $rses_signed['public_key_fingerprint'] ?? '' )
				: TallyImportRepository::rses_public_key_fingerprint( is_array( $rses_manifest['public_key'] ?? null ) ? $rses_manifest['public_key'] : array() ),
			'encrypted_sum_hash'     => HashService::rses_hash_json( $rses_manifest['encrypted_tallies'] ?? array() ),
			'decrypted_result_hash'  => HashService::rses_hash_json( $rses_raw ),
			'ballot_count'           => $rses_manifest['manifest']['ballot_count'] ?? ( $rses_import->ballot_count ?? 0 ),
			'threshold'              => $rses_result['threshold'] ?? 0,
			'verification_status'    => is_array( $rses_signed ) ? 'decrypted-signed' : 'decrypted',
			'certified_at'           => '',
			'ballot'                 => $rses_ballot,
			'decrypted_results'      => $rses_raw,
			'humanized_results'      => $rses_humanized,
			'signed_results'         => $rses_signed,
			'signature_scheme'       => is_array( $rses_signed ) ? (string) ( $rses_signed['scheme'] ?? '' ) : '',
		);
	}
}
