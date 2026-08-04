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
	 * Lines per page (Helvetica 10pt ≈ 14pt leading on US Letter).
	 */
	private const RSES_LINES_PER_PAGE = 58;

	/**
	 * Generate a multi-page PDF from text lines.
	 *
	 * @param array<int,string> $lines Text lines.
	 * @return string PDF binary content.
	 */
	public static function rses_generate( array $lines ): string {
		$rses_normalized = array();
		foreach ( $lines as $rses_line ) {
			$rses_line = str_replace( array( "\r\n", "\r" ), "\n", (string) $rses_line );
			foreach ( explode( "\n", $rses_line ) as $rses_part ) {
				// Soft-wrap very long lines (JSON) for Helvetica width ≈ 90 chars.
				$rses_part = (string) $rses_part;
				if ( strlen( $rses_part ) <= 90 ) {
					$rses_normalized[] = $rses_part;
					continue;
				}
				while ( strlen( $rses_part ) > 90 ) {
					$rses_normalized[] = substr( $rses_part, 0, 90 );
					$rses_part           = substr( $rses_part, 90 );
				}
				if ( '' !== $rses_part ) {
					$rses_normalized[] = $rses_part;
				}
			}
		}

		if ( empty( $rses_normalized ) ) {
			$rses_normalized = array( '' );
		}

		$rses_pages = array_chunk( $rses_normalized, self::RSES_LINES_PER_PAGE );
		$rses_page_count = count( $rses_pages );

		// Object layout: 1=Catalog, 2=Pages, 3=Font, then pairs (Page, Content) per page.
		$rses_objects   = array();
		$rses_objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$rses_kids       = array();
		$rses_next_id    = 4;

		$page_ids    = array();
		$content_ids = array();
		foreach ( $rses_pages as $rses_idx => $rses_page_lines ) {
			$page_ids[ $rses_idx ]    = $rses_next_id++;
			$content_ids[ $rses_idx ] = $rses_next_id++;
			$rses_kids[]              = $page_ids[ $rses_idx ] . ' 0 R';
		}

		$rses_objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $rses_kids ) . '] /Count ' . $rses_page_count . ' >>';
		$rses_objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

		foreach ( $rses_pages as $rses_idx => $rses_page_lines ) {
			$rses_pid = $page_ids[ $rses_idx ];
			$rses_cid = $content_ids[ $rses_idx ];

			$rses_objects[ $rses_pid ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '
				. $rses_cid . ' 0 R /Resources << /Font << /F1 3 0 R >> >> >>';

			$rses_stream = "BT /F1 10 Tf 14 TL 50 750 Td\n";
			foreach ( $rses_page_lines as $rses_i => $rses_text_line ) {
				$rses_esc = self::rses_escape_pdf_string( $rses_text_line );
				if ( 0 === $rses_i ) {
					$rses_stream .= "({$rses_esc}) Tj\n";
				} else {
					$rses_stream .= "T* ({$rses_esc}) Tj\n";
				}
			}
			$rses_stream .= 'ET';
			$rses_objects[ $rses_cid ] = '<< /Length ' . strlen( $rses_stream ) . " >> stream\n{$rses_stream}\nendstream";
		}

		ksort( $rses_objects, SORT_NUMERIC );

		$rses_pdf     = "%PDF-1.4\n";
		$rses_offsets = array();
		$rses_max_id  = max( array_keys( $rses_objects ) );

		for ( $rses_id = 1; $rses_id <= $rses_max_id; ++$rses_id ) {
			if ( ! isset( $rses_objects[ $rses_id ] ) ) {
				continue;
			}
			$rses_offsets[ $rses_id ] = strlen( $rses_pdf );
			$rses_pdf                .= $rses_id . ' 0 obj ' . $rses_objects[ $rses_id ] . " endobj\n";
		}

		$rses_xref_pos = strlen( $rses_pdf );
		$rses_size     = $rses_max_id + 1;
		$rses_pdf     .= "xref\n0 {$rses_size}\n";
		$rses_pdf     .= "0000000000 65535 f \n";
		for ( $rses_id = 1; $rses_id <= $rses_max_id; ++$rses_id ) {
			if ( isset( $rses_offsets[ $rses_id ] ) ) {
				$rses_pdf .= sprintf( "%010d 00000 n \n", $rses_offsets[ $rses_id ] );
			} else {
				$rses_pdf .= "0000000000 65535 f \n";
			}
		}

		$rses_pdf .= "trailer << /Size {$rses_size} /Root 1 0 R >>\n";
		$rses_pdf .= "startxref\n{$rses_xref_pos}\n%%EOF";

		return $rses_pdf;
	}

	/**
	 * Escape string for PDF literal strings.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function rses_escape_pdf_string( string $text ): string {
		// PDF literal strings are Latin-1-ish; drop non-ASCII for Helvetica safety.
		$rses_ascii = preg_replace( '/[^\x09\x20-\x7E]/', '?', $text );
		if ( ! is_string( $rses_ascii ) ) {
			$rses_ascii = $text;
		}
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $rses_ascii );
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

		echo $rses_pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
