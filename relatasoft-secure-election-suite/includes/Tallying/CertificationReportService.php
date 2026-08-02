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
	 * Export certification as PDF.
	 *
	 * @param array<string,mixed> $report    Report data.
	 * @param int                 $import_id Import ID.
	 */
	public static function rses_export_pdf( array $report, int $import_id ): void {
		$rses_lines = array(
			__( 'RelataSoft Secure Election Suite - Certification Report', 'relatasoft-secure-election-suite' ),
			'',
			__( 'Election:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['election_title'] ?? '' ),
			__( 'Round:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['round_number'] ?? '' ),
			__( 'Status:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['verification_status'] ?? '' ),
			__( 'Certified at:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['certified_at'] ?? '' ),
			__( 'Public key hash:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['public_key_hash'] ?? '' ),
			__( 'Decrypted result hash:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['decrypted_result_hash'] ?? '' ),
			__( 'Ballot count:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['ballot_count'] ?? 0 ),
			__( 'Threshold:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['threshold'] ?? 0 ),
		);

		foreach ( $report['decrypted_results'] ?? array() as $rses_result ) {
			$rses_lines[] = sprintf(
				'Q%s O%s: %d',
				$rses_result['question_id'] ?? '-',
				$rses_result['option_id'] ?? '-',
				$rses_result['count'] ?? 0
			);
		}

		PdfReport::rses_send_download( 'certification-report-' . $import_id . '.pdf', $rses_lines );
	}

	/**
	 * Export certification as ZIP.
	 *
	 * @param array<string,mixed> $report    Report data.
	 * @param int                 $import_id Import ID.
	 */
	public static function rses_export_zip( array $report, int $import_id ): void {
		$rses_files = array(
			'certification-report.json' => wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'decrypted-results.json'    => wp_json_encode( $report['decrypted_results'] ?? array(), JSON_PRETTY_PRINT ),
			'verification.json'         => wp_json_encode(
				array(
					'public_key_hash'       => $report['public_key_hash'] ?? '',
					'encrypted_sum_hash'    => $report['encrypted_sum_hash'] ?? '',
					'decrypted_result_hash' => $report['decrypted_result_hash'] ?? '',
					'verification_status'   => $report['verification_status'] ?? '',
				),
				JSON_PRETTY_PRINT
			),
			'README.txt'                => __( 'RelataSoft Secure Election Suite - Certification Export', 'relatasoft-secure-election-suite' ),
		);

		$rses_pdf_lines = array(
			__( 'Certification Report', 'relatasoft-secure-election-suite' ),
			$report['election_title'] ?? '',
		);
		$rses_files['certification-report.pdf'] = \RelataSoft\SecureElectionSuite\Exports\PdfReport::rses_generate( $rses_pdf_lines );

		$rses_files['checksums.json'] = wp_json_encode( ManifestBuilder::rses_build_checksums( $rses_files ), JSON_PRETTY_PRINT );

		ZipExport::rses_send_download( 'certification-' . $import_id . '.zip', $rses_files );
	}
}
