<?php
/**
 * Simple PDF report generator.
 *
 * @package RelataSoft\SecureElectionSuite\Exports
 */

namespace RelataSoft\SecureElectionSuite\Exports;

defined( 'ABSPATH' ) || exit;

/**
 * Generates basic PDF certification reports without external dependencies.
 */
class PdfReport {

	/**
	 * Generate a minimal PDF from text lines.
	 *
	 * @param array<int,string> $lines Text lines.
	 * @return string PDF binary content.
	 */
	public static function rses_generate( array $lines ): string {
		$rses_text = implode( "\n", $lines );
		$rses_text = self::rses_escape_pdf_string( $rses_text );

		$rses_objects = array();
		$rses_objects[] = '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj';
		$rses_objects[] = '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj';
		$rses_objects[] = '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj';

		$rses_stream = "BT /F1 10 Tf 50 750 Td ({$rses_text}) Tj ET";
		$rses_stream_len = strlen( $rses_stream );
		$rses_objects[] = "4 0 obj << /Length {$rses_stream_len} >> stream\n{$rses_stream}\nendstream endobj";
		$rses_objects[] = '5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj';

		$rses_pdf = "%PDF-1.4\n";
		$rses_offsets = array();
		$rses_pos = strlen( $rses_pdf );

		foreach ( $rses_objects as $rses_idx => $rses_obj ) {
			$rses_offsets[ $rses_idx + 1 ] = $rses_pos;
			$rses_pdf .= $rses_obj . "\n";
			$rses_pos = strlen( $rses_pdf );
		}

		$rses_xref_pos = strlen( $rses_pdf );
		$rses_pdf .= "xref\n0 " . ( count( $rses_objects ) + 1 ) . "\n";
		$rses_pdf .= "0000000000 65535 f \n";

		for ( $rses_i = 1; $rses_i <= count( $rses_objects ); ++$rses_i ) {
			$rses_pdf .= sprintf( "%010d 00000 n \n", $rses_offsets[ $rses_i ] );
		}

		$rses_pdf .= "trailer << /Size " . ( count( $rses_objects ) + 1 ) . " /Root 1 0 R >>\n";
		$rses_pdf .= "startxref\n{$rses_xref_pos}\n%%EOF";

		return $rses_pdf;
	}

	/**
	 * Escape string for PDF.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function rses_escape_pdf_string( string $text ): string {
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
	}

	/**
	 * Send PDF download.
	 *
	 * @param string            $filename Filename.
	 * @param array<int,string> $lines    Content lines.
	 */
	public static function rses_send_download( string $filename, array $lines ): void {
		$rses_pdf = self::rses_generate( $lines );

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $rses_pdf ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		echo $rses_pdf;
		exit;
	}
}
