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
	 * @param string                                              $filename Archive filename.
	 * @param array<string, string|array{path:string}>            $files    Path => content string, or ['path' => absolute file].
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

		$rses_cleanup = array();

		foreach ( $files as $rses_path => $rses_content ) {
			$rses_name = sanitize_file_name( $rses_path );

			if ( is_array( $rses_content ) && ! empty( $rses_content['path'] ) && is_readable( (string) $rses_content['path'] ) ) {
				$rses_abs = (string) $rses_content['path'];
				$rses_zip->addFile( $rses_abs, $rses_name );
				$rses_cleanup[] = $rses_abs;
				continue;
			}

			$rses_zip->addFromString( $rses_name, (string) $rses_content );
		}

		$rses_zip->close();

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $rses_tmp ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		readfile( $rses_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		unlink( $rses_tmp );

		foreach ( $rses_cleanup as $rses_abs ) {
			if ( is_string( $rses_abs ) && is_file( $rses_abs ) ) {
				unlink( $rses_abs );
			}
		}
		exit;
	}
}
