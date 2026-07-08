<?php
/**
 * ZIP export helper.
 *
 * @package RelataSoft\SecureElectionSuite\Exports
 */

namespace RelataSoft\SecureElectionSuite\Exports;

defined( 'ABSPATH' ) || exit;

/**
 * ZIP file download utility.
 */
class ZipExport {

	/**
	 * Send ZIP file download.
	 *
	 * @param string               $filename Archive filename.
	 * @param array<string,string> $files    Files map (path => content).
	 */
	public static function rses_send_download( string $filename, array $files ): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die(
				esc_html__( 'ZIP export requires the ZipArchive PHP extension.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'ZIP Unavailable', 'relatasoft-secure-election-suite' ),
				array( 'response' => 500 )
			);
		}

		$rses_tmp = wp_tempnam( $filename );

		if ( ! $rses_tmp ) {
			wp_die( esc_html__( 'Failed to create temporary file.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_zip = new \ZipArchive();

		if ( true !== $rses_zip->open( $rses_tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Failed to create ZIP archive.', 'relatasoft-secure-election-suite' ) );
		}

		foreach ( $files as $rses_path => $rses_content ) {
			$rses_zip->addFromString( sanitize_file_name( $rses_path ), (string) $rses_content );
		}

		$rses_zip->close();

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $rses_tmp ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		readfile( $rses_tmp );
		unlink( $rses_tmp );
		exit;
	}
}
