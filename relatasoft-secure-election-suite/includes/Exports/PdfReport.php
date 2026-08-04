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
 *
 * Text is encoded as Windows-1252 (WinAnsiEncoding) so Portuguese accents
 * render correctly with built-in Helvetica.
 */
class PdfReport {

	/**
	 * Lines per page (Helvetica 10pt ≈ 14pt leading on US Letter).
	 */
	private const RSES_LINES_PER_PAGE = 58;

	/**
	 * Approx. characters per line at 10pt Helvetica with 50pt margins.
	 */
	private const RSES_CHARS_PER_LINE = 90;

	/**
	 * Generate a multi-page PDF from UTF-8 text lines.
	 *
	 * @param array<int,string> $lines UTF-8 text lines.
	 * @return string PDF binary content.
	 */
	public static function rses_generate( array $lines ): string {
		$rses_normalized = array();
		foreach ( $lines as $rses_line ) {
			$rses_line = str_replace( array( "\r\n", "\r" ), "\n", (string) $rses_line );
			foreach ( explode( "\n", $rses_line ) as $rses_part ) {
				$rses_win = self::rses_utf8_to_winansi( (string) $rses_part );
				foreach ( self::rses_wrap_winansi( $rses_win, self::RSES_CHARS_PER_LINE ) as $rses_wrapped ) {
					$rses_normalized[] = $rses_wrapped;
				}
			}
		}

		if ( empty( $rses_normalized ) ) {
			$rses_normalized = array( '' );
		}

		$rses_pages      = array_chunk( $rses_normalized, self::RSES_LINES_PER_PAGE );
		$rses_page_count = count( $rses_pages );

		// Object layout: 1=Catalog, 2=Pages, 3=Font, then pairs (Page, Content) per page.
		$rses_objects    = array();
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
		$rses_objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

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
			$rses_stream                 .= 'ET';
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
	 * Convert UTF-8 to Windows-1252 for WinAnsiEncoding.
	 *
	 * @param string $text UTF-8 text.
	 */
	private static function rses_utf8_to_winansi( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		// Normalize a few characters that TRANSLIT may mangle oddly.
		$rses_map = array(
			"\u{2014}" => "\x97", // em dash —
			"\u{2013}" => "\x96", // en dash –
			"\u{2018}" => "\x91", // ‘
			"\u{2019}" => "\x92", // ’
			"\u{201C}" => "\x93", // “
			"\u{201D}" => "\x94", // ”
			"\u{2026}" => "\x85", // …
			"\u{00A0}" => ' ',    // nbsp
		);
		$text = strtr( $text, $rses_map );

		if ( function_exists( 'iconv' ) ) {
			$rses_converted = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $rses_converted && is_string( $rses_converted ) ) {
				return $rses_converted;
			}
		}

		if ( function_exists( 'mb_convert_encoding' ) ) {
			$rses_converted = @mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_string( $rses_converted ) ) {
				return $rses_converted;
			}
		}

		$rses_fallback = preg_replace( '/[^\x09\x20-\x7E]/', '?', $text );
		return is_string( $rses_fallback ) ? $rses_fallback : $text;
	}

	/**
	 * Soft-wrap a WinAnsi string, preferring breaks at spaces.
	 *
	 * @param string $text WinAnsi bytes.
	 * @param int    $width Max chars per line.
	 * @return array<int,string>
	 */
	private static function rses_wrap_winansi( string $text, int $width ): array {
		if ( strlen( $text ) <= $width ) {
			return array( $text );
		}

		$rses_out = array();
		while ( strlen( $text ) > $width ) {
			$rses_chunk = substr( $text, 0, $width );
			$rses_break = strrpos( $rses_chunk, ' ' );
			if ( false !== $rses_break && $rses_break >= (int) ( $width * 0.6 ) ) {
				$rses_out[] = substr( $text, 0, $rses_break );
				$text       = ltrim( substr( $text, $rses_break + 1 ) );
			} else {
				$rses_out[] = $rses_chunk;
				$text       = substr( $text, $width );
			}
		}
		if ( '' !== $text ) {
			$rses_out[] = $text;
		}

		return $rses_out;
	}

	/**
	 * Escape a WinAnsi string for PDF literal parentheses syntax.
	 *
	 * @param string $text Already WinAnsi-encoded text.
	 */
	private static function rses_escape_pdf_string( string $text ): string {
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
	}

	/**
	 * Send PDF download.
	 *
	 * @param string            $filename Filename.
	 * @param array<int,string> $lines    UTF-8 content lines.
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
