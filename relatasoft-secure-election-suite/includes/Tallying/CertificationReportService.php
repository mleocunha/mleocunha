<?php
/**
 * Certification report export service.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Exports\ManifestBuilder;
use RelataSoft\SecureElectionSuite\Exports\PdfReport;
use RelataSoft\SecureElectionSuite\Exports\ZipExport;

defined( 'ABSPATH' ) || exit;

/**
 * Generates certification PDF and ZIP exports.
 */
class CertificationReportService {

	/**
	 * Ensure the report carries a humanized result block (builds if missing).
	 *
	 * @param array<string,mixed> $report    Report.
	 * @param array<string,mixed> $manifest  Optional import manifest for ballot labels.
	 * @param string              $sort      Sort mode.
	 * @return array<string,mixed>
	 */
	public static function rses_with_humanized( array $report, array $manifest = array(), string $sort = DecryptedResultsPresenter::RSES_SORT_COUNT_DESC ): array {
		if ( ! empty( $report['humanized_results'] ) && is_array( $report['humanized_results'] ) ) {
			// Re-sort if caller asks for a different order.
			if ( ( $report['humanized_results']['sort'] ?? '' ) === DecryptedResultsPresenter::rses_normalize_sort( $sort ) ) {
				return $report;
			}
		}

		$rses_ballot = is_array( $manifest['ballot'] ?? null )
			? $manifest['ballot']
			: ( is_array( $report['ballot'] ?? null ) ? $report['ballot'] : array() );

		$rses_raw = is_array( $report['decrypted_results'] ?? null ) ? $report['decrypted_results'] : array();
		$report['humanized_results'] = DecryptedResultsPresenter::rses_humanize( $rses_raw, $rses_ballot, $sort );
		if ( empty( $report['ballot'] ) && ! empty( $rses_ballot ) ) {
			$report['ballot'] = $rses_ballot;
		}

		return $report;
	}

	/**
	 * Export certification as PDF (humanized results first; raw appendix).
	 *
	 * @param array<string,mixed> $report    Report data.
	 * @param int                 $import_id Import ID.
	 */
	public static function rses_export_pdf( array $report, int $import_id ): void {
		$rses_import   = TallyImportRepository::rses_get( $import_id );
		$rses_manifest = $rses_import ? TallyImportRepository::rses_get_manifest( $rses_import ) : array();
		$rses_report   = self::rses_with_humanized( $report, $rses_manifest );

		if ( empty( $rses_report['election_title'] ) && $rses_import ) {
			$rses_report['election_title'] = TallyImportRepository::rses_display_election_title( $rses_import );
		}
		if ( empty( $rses_report['round_title'] ) && $rses_import ) {
			$rses_report['round_title'] = TallyImportRepository::rses_display_round_title( $rses_import );
		}

		$rses_signed_pdf = SignedResultsService::rses_get_pdf( $import_id );
		if ( is_string( $rses_signed_pdf ) && '' !== $rses_signed_pdf ) {
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'signed-results-import-' . $import_id . '.pdf' ) . '"' );
			header( 'Content-Length: ' . strlen( $rses_signed_pdf ) );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			echo $rses_signed_pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		$rses_lines = DecryptedResultsPresenter::rses_pdf_lines(
			$rses_report,
			is_array( $rses_report['humanized_results'] ) ? $rses_report['humanized_results'] : array()
		);

		PdfReport::rses_send_download( 'certification-report-' . $import_id . '.pdf', $rses_lines );
	}

	/**
	 * Export certification as ZIP.
	 *
	 * @param array<string,mixed> $report    Report data.
	 * @param int                 $import_id Import ID.
	 */
	public static function rses_export_zip( array $report, int $import_id ): void {
		$rses_import   = TallyImportRepository::rses_get( $import_id );
		$rses_manifest = $rses_import ? TallyImportRepository::rses_get_manifest( $rses_import ) : array();
		$rses_report   = self::rses_with_humanized( $report, $rses_manifest );

		$rses_pdf_lines = DecryptedResultsPresenter::rses_pdf_lines(
			$rses_report,
			is_array( $rses_report['humanized_results'] ) ? $rses_report['humanized_results'] : array()
		);

		$rses_signed = is_array( $rses_report['signed_results'] ?? null )
			? $rses_report['signed_results']
			: SignedResultsService::rses_get_package( $import_id );
		$rses_signed_pdf = SignedResultsService::rses_get_pdf( $import_id );
		$rses_pdf_bytes  = is_string( $rses_signed_pdf ) ? $rses_signed_pdf : PdfReport::rses_generate( $rses_pdf_lines );

		$rses_files = array(
			'certification-report.json' => wp_json_encode( $rses_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'humanized-results.json'    => wp_json_encode( $rses_report['humanized_results'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'decrypted-results.json'    => wp_json_encode( $rses_report['decrypted_results'] ?? array(), JSON_PRETTY_PRINT ),
			'verification.json'         => wp_json_encode(
				array(
					'public_key_hash'       => $rses_report['public_key_hash'] ?? '',
					'encrypted_sum_hash'    => $rses_report['encrypted_sum_hash'] ?? '',
					'decrypted_result_hash' => $rses_report['decrypted_result_hash'] ?? '',
					'verification_status'   => $rses_report['verification_status'] ?? '',
				),
				JSON_PRETTY_PRINT
			),
			'certification-report.pdf'  => $rses_pdf_bytes,
			'README.txt'                => __( 'RelataSoft Secure Election Suite - Certification Export. Prefer signed-results.json for independent Schnorr verification with the election public key. humanized-results.json and the PDF use ballot labels; decrypted-results.json is the raw technical tally.', 'relatasoft-secure-election-suite' ),
		);

		if ( is_array( $rses_signed ) ) {
			$rses_files['signed-results.json'] = wp_json_encode( $rses_signed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}

		$rses_files['checksums.json'] = wp_json_encode( ManifestBuilder::rses_build_checksums( $rses_files ), JSON_PRETTY_PRINT );

		ZipExport::rses_send_download( 'certification-' . $import_id . '.zip', $rses_files );
	}
}
