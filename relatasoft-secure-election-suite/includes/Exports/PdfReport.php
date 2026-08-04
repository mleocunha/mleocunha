<?php
/**
 * Unicode PDF report generator (UTF-8 text via embedded TrueType).
 *
 * @package RelataSoft\SecureElectionSuite\Exports
 */

namespace RelataSoft\SecureElectionSuite\Exports;

defined( 'ABSPATH' ) || exit;

/**
 * Generates multi-page PDF certification reports with full UTF-8 support.
 */
class PdfReport {

	/**
	 * Lines per page (10pt, 14pt leading on US Letter).
	 */
	private const RSES_LINES_PER_PAGE = 58;

	/**
	 * Approx. characters per line at 10pt with 50pt margins.
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
				foreach ( self::rses_wrap_utf8( (string) $rses_part, self::RSES_CHARS_PER_LINE ) as $rses_wrapped ) {
					$rses_normalized[] = $rses_wrapped;
				}
			}
		}

		if ( empty( $rses_normalized ) ) {
			$rses_normalized = array( '' );
		}

		try {
			$rses_font = PdfTrueTypeFont::rses_load_default();
		} catch ( \Throwable $rses_e ) {
			// Last-resort ASCII PDF if the bundled font is missing.
			return self::rses_generate_ascii_fallback( $rses_normalized );
		}

		$rses_pages      = array_chunk( $rses_normalized, self::RSES_LINES_PER_PAGE );
		$rses_page_count = count( $rses_pages );
		$rses_ps_name    = $rses_font->rses_postscript_name();
		$rses_bbox       = $rses_font->rses_bbox();
		$rses_widths     = $rses_font->rses_widths_array_for_lines( $rses_normalized );
		$rses_tounicode  = $rses_font->rses_tounicode_cmap( $rses_normalized );
		$rses_font_bytes = $rses_font->rses_font_bytes();
		$rses_font_z     = gzcompress( $rses_font_bytes );
		if ( false === $rses_font_z ) {
			$rses_font_z = $rses_font_bytes;
			$rses_font_filter = '';
		} else {
			$rses_font_filter = '/Filter /FlateDecode ';
		}

		/*
		 * Object map:
		 * 1 Catalog, 2 Pages, 3 Type0 Font, 4 CIDFont, 5 FontDescriptor,
		 * 6 FontFile2, 7 ToUnicode, then page/content pairs.
		 */
		$rses_objects    = array();
		$rses_objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

		$rses_kids    = array();
		$rses_next_id = 8;
		$page_ids     = array();
		$content_ids  = array();
		foreach ( $rses_pages as $rses_idx => $_page ) {
			$page_ids[ $rses_idx ]    = $rses_next_id++;
			$content_ids[ $rses_idx ] = $rses_next_id++;
			$rses_kids[]              = $page_ids[ $rses_idx ] . ' 0 R';
		}

		$rses_objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $rses_kids ) . '] /Count ' . $rses_page_count . ' >>';

		$rses_objects[3] = '<< /Type /Font /Subtype /Type0 /BaseFont /' . $rses_ps_name
			. ' /Encoding /Identity-H /DescendantFonts [4 0 R] /ToUnicode 7 0 R >>';

		$rses_objects[4] = '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $rses_ps_name
			. ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
			. ' /FontDescriptor 5 0 R /DW ' . (int) $rses_font->rses_missing_width()
			. ' /W ' . $rses_widths
			. ' /CIDToGIDMap /Identity >>';

		$rses_objects[5] = '<< /Type /FontDescriptor /FontName /' . $rses_ps_name
			. ' /Flags 32'
			. ' /FontBBox [' . implode( ' ', $rses_bbox ) . ']'
			. ' /ItalicAngle 0'
			. ' /Ascent ' . (int) $rses_font->rses_ascent()
			. ' /Descent ' . (int) $rses_font->rses_descent()
			. ' /CapHeight ' . (int) $rses_font->rses_ascent()
			. ' /StemV 80'
			. ' /FontFile2 6 0 R >>';

		$rses_objects[6] = '<< ' . $rses_font_filter . '/Length ' . strlen( $rses_font_z )
			. ' /Length1 ' . strlen( $rses_font_bytes ) . " >> stream\n"
			. $rses_font_z . "\nendstream";

		$rses_objects[7] = '<< /Length ' . strlen( $rses_tounicode ) . " >> stream\n"
			. $rses_tounicode . "\nendstream";

		foreach ( $rses_pages as $rses_idx => $rses_page_lines ) {
			$rses_pid = $page_ids[ $rses_idx ];
			$rses_cid = $content_ids[ $rses_idx ];

			$rses_objects[ $rses_pid ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '
				. $rses_cid . ' 0 R /Resources << /Font << /F1 3 0 R >> >> >>';

			$rses_stream = "BT /F1 10 Tf 14 TL 50 750 Td\n";
			foreach ( $rses_page_lines as $rses_i => $rses_text_line ) {
				$rses_hex = $rses_font->rses_encode_text( $rses_text_line );
				if ( 0 === $rses_i ) {
					$rses_stream .= $rses_hex . " Tj\n";
				} else {
					$rses_stream .= 'T* ' . $rses_hex . " Tj\n";
				}
			}
			$rses_stream                 .= 'ET';
			$rses_objects[ $rses_cid ] = '<< /Length ' . strlen( $rses_stream ) . " >> stream\n{$rses_stream}\nendstream";
		}

		return self::rses_assemble_pdf( $rses_objects );
	}

	/**
	 * Soft-wrap UTF-8 by character count, preferring spaces.
	 *
	 * @param string $text  UTF-8 text.
	 * @param int    $width Max characters.
	 * @return array<int,string>
	 */
	private static function rses_wrap_utf8( string $text, int $width ): array {
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text, 'UTF-8' ) <= $width ) {
				return array( $text );
			}
			$rses_out = array();
			while ( mb_strlen( $text, 'UTF-8' ) > $width ) {
				$rses_chunk = mb_substr( $text, 0, $width, 'UTF-8' );
				$rses_break = mb_strrpos( $rses_chunk, ' ', 0, 'UTF-8' );
				if ( false !== $rses_break && $rses_break >= (int) ( $width * 0.6 ) ) {
					$rses_out[] = mb_substr( $text, 0, $rses_break, 'UTF-8' );
					$text       = ltrim( mb_substr( $text, $rses_break + 1, null, 'UTF-8' ) );
				} else {
					$rses_out[] = $rses_chunk;
					$text       = mb_substr( $text, $width, null, 'UTF-8' );
				}
			}
			if ( '' !== $text ) {
				$rses_out[] = $text;
			}
			return $rses_out;
		}

		// Byte fallback (ASCII-ish).
		if ( strlen( $text ) <= $width ) {
			return array( $text );
		}
		$rses_out = array();
		while ( strlen( $text ) > $width ) {
			$rses_out[] = substr( $text, 0, $width );
			$text       = substr( $text, $width );
		}
		if ( '' !== $text ) {
			$rses_out[] = $text;
		}
		return $rses_out;
	}

	/**
	 * ASCII-only Helvetica fallback when the TTF cannot be loaded.
	 *
	 * @param array<int,string> $lines Lines (already wrapped).
	 */
	private static function rses_generate_ascii_fallback( array $lines ): string {
		$rses_pages      = array_chunk( $lines, self::RSES_LINES_PER_PAGE );
		$rses_page_count = count( $rses_pages );
		$rses_objects    = array();
		$rses_objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$rses_kids       = array();
		$rses_next_id    = 4;
		$page_ids        = array();
		$content_ids     = array();
		foreach ( $rses_pages as $rses_idx => $_p ) {
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
				$rses_ascii = preg_replace( '/[^\x09\x20-\x7E]/', '?', $rses_text_line );
				$rses_ascii = is_string( $rses_ascii ) ? $rses_ascii : $rses_text_line;
				$rses_esc   = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $rses_ascii );
				$rses_stream .= ( 0 === $rses_i ? '' : 'T* ' ) . "({$rses_esc}) Tj\n";
			}
			$rses_stream                 .= 'ET';
			$rses_objects[ $rses_cid ] = '<< /Length ' . strlen( $rses_stream ) . " >> stream\n{$rses_stream}\nendstream";
		}

		return self::rses_assemble_pdf( $rses_objects );
	}

	/**
	 * @param array<int,string> $objects Object id => body (without obj/endobj).
	 */
	private static function rses_assemble_pdf( array $objects ): string {
		ksort( $objects, SORT_NUMERIC );
		$rses_pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; // Binary marker encourages binary treatment.
		$rses_offsets = array();
		$rses_max_id  = max( array_keys( $objects ) );

		for ( $rses_id = 1; $rses_id <= $rses_max_id; ++$rses_id ) {
			if ( ! isset( $objects[ $rses_id ] ) ) {
				continue;
			}
			$rses_offsets[ $rses_id ] = strlen( $rses_pdf );
			$rses_pdf                .= $rses_id . ' 0 obj ' . $objects[ $rses_id ] . " endobj\n";
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
