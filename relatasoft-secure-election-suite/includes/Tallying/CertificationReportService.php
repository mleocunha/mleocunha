<?php
/**
 * Certification report export service.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Exports\CertificationPdf;
use RelataSoft\SecureElectionSuite\Exports\ManifestBuilder;
use RelataSoft\SecureElectionSuite\Exports\ZipExport;

defined( 'ABSPATH' ) || exit;

/**
 * Generates certification PDF and ZIP exports.
 */
class CertificationReportService {

	/**
	 * Ensure the report carries a humanized result block (builds if missing).
	 *
	 * @param array<string,mixed> $report   Report.
	 * @param array<string,mixed> $manifest Optional import manifest for ballot labels.
	 * @param string              $sort     Sort mode.
	 * @return array<string,mixed>
	 */
	public static function rses_with_humanized( array $report, array $manifest = array(), string $sort = DecryptedResultsPresenter::RSES_SORT_COUNT_DESC ): array {
		if ( ! empty( $report['humanized_results'] ) && is_array( $report['humanized_results'] ) ) {
			if ( ( $report['humanized_results']['sort'] ?? '' ) === DecryptedResultsPresenter::rses_normalize_sort( $sort ) ) {
				return $report;
			}
		}

		$rses_ballot = is_array( $manifest['ballot'] ?? null )
			? $manifest['ballot']
			: ( is_array( $report['ballot'] ?? null ) ? $report['ballot'] : array() );

		$rses_raw                        = is_array( $report['decrypted_results'] ?? null ) ? $report['decrypted_results'] : array();
		$report['humanized_results']     = DecryptedResultsPresenter::rses_humanize( $rses_raw, $rses_ballot, $sort );
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

		$rses_pdf = CertificationPdf::rses_generate(
			$rses_report,
			is_array( $rses_report['humanized_results'] ) ? $rses_report['humanized_results'] : array(),
			null
		);

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'certification-report-' . $import_id . '.pdf' ) . '"' );
		header( 'Content-Length: ' . strlen( $rses_pdf ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		echo $rses_pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
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

		if ( empty( $rses_report['election_title'] ) && $rses_import ) {
			$rses_report['election_title'] = TallyImportRepository::rses_display_election_title( $rses_import );
		}
		if ( empty( $rses_report['round_title'] ) && $rses_import ) {
			$rses_report['round_title'] = TallyImportRepository::rses_display_round_title( $rses_import );
		}

		$rses_pdf_bytes = CertificationPdf::rses_generate(
			$rses_report,
			is_array( $rses_report['humanized_results'] ) ? $rses_report['humanized_results'] : array(),
			null
		);

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
			'README.txt'                => __(
				"RelataSoft Secure Election Suite — Certification Export\n\n"
				. "1) humanized-results.json and certification-report.pdf — plain-language tally (ballot labels and vote counts) for electoral authorities, voters, observers, and candidates.\n"
				. "2) decrypted-results.json and verification.json — technical appendix for auditors.\n"
				. "3) checksums.json — integrity of the files in this ZIP.\n\n"
				. 'Independent cryptographic signature of the results bulletin (threshold signing) is deferred and is not included in this version. Do not treat this package as a cryptographically signed results bulletin.',
				'relatasoft-secure-election-suite'
			),
		);

		$rses_files['checksums.json'] = wp_json_encode( ManifestBuilder::rses_build_checksums( $rses_files ), JSON_PRETTY_PRINT );

		ZipExport::rses_send_download( 'certification-' . $import_id . '.zip', $rses_files );
	}
}
