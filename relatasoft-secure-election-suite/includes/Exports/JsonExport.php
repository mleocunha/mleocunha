<?php
/**
 * JSON export helper.
 *
 * @package RelataSoft\SecureElectionSuite\Exports
 */

namespace RelataSoft\SecureElectionSuite\Exports;

defined( 'ABSPATH' ) || exit;

/**
 * JSON file download utility.
 */
class JsonExport {

	/**
	 * Send JSON file download.
	 *
	 * @param string              $filename Filename.
	 * @param array<string,mixed> $data     Data.
	 */
	public static function rses_send_download( string $filename, array $data ): void {
		$rses_json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( (string) $rses_json ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		echo $rses_json;
		exit;
	}
}
