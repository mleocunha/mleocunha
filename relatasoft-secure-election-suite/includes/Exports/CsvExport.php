<?php
/**
 * CSV export helper.
 *
 * @package RelataSoft\SecureElectionSuite\Exports
 */

namespace RelataSoft\SecureElectionSuite\Exports;

defined( 'ABSPATH' ) || exit;

/**
 * CSV file download utility.
 */
class CsvExport {

	/**
	 * Send CSV file download.
	 *
	 * @param string                   $filename Filename.
	 * @param array<int,string>        $headers  Column headers.
	 * @param array<int,array<int,mixed>> $rows     Data rows.
	 */
	public static function rses_send_download( string $filename, array $headers, array $rows ): void {
		$rses_output = fopen( 'php://temp', 'r+' );

		if ( false === $rses_output ) {
			wp_die( esc_html__( 'Failed to create CSV.', 'relatasoft-secure-election-suite' ) );
		}

		fputcsv( $rses_output, $headers );

		foreach ( $rows as $rses_row ) {
			fputcsv( $rses_output, $rses_row );
		}

		rewind( $rses_output );
		$rses_csv = stream_get_contents( $rses_output );
		fclose( $rses_output );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( (string) $rses_csv ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		echo $rses_csv;
		exit;
	}
}
