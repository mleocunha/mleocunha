<?php
/**
 * Minimal TrueType loader for Unicode PDF embedding (Identity-H).
 *
 * @package RelataSoft\SecureElectionSuite\Exports
 */

namespace RelataSoft\SecureElectionSuite\Exports;

defined( 'ABSPATH' ) || exit;

/**
 * Parses cmap/hmtx/head metrics from a TTF and encodes UTF-8 text as CID hex.
 */
class PdfTrueTypeFont {

	/** @var string */
	private string $rses_data;

	/** @var array<int,int> Unicode codepoint => glyph id */
	private array $rses_cmap = array();

	/** @var array<int,int> Glyph id => width in PDF units (1/1000 em) */
	private array $rses_widths = array();

	/** @var int */
	private int $rses_units_per_em = 1000;

	/** @var array{0:int,1:int,2:int,3:int} */
	private array $rses_bbox = array( 0, 0, 0, 0 );

	/** @var int */
	private int $rses_ascent = 800;

	/** @var int */
	private int $rses_descent = -200;

	/** @var int */
	private int $rses_missing_width = 500;

	/** @var string */
	private string $rses_postscript_name = 'EmbeddedSans';

	/**
	 * @param string $ttf_path Absolute path to a .ttf file.
	 */
	public function __construct( string $ttf_path ) {
		if ( ! is_readable( $ttf_path ) ) {
			throw new \RuntimeException( 'TTF font not readable: ' . $ttf_path );
		}
		$rses_raw = file_get_contents( $ttf_path );
		if ( false === $rses_raw || '' === $rses_raw ) {
			throw new \RuntimeException( 'TTF font empty: ' . $ttf_path );
		}
		$this->rses_data = $rses_raw;
		$this->rses_parse();
	}

	/**
	 * Default bundled DejaVu Sans path.
	 */
	public static function rses_default_path(): string {
		return RSES_PLUGIN_DIR . 'assets/fonts/DejaVuSans.ttf';
	}

	/**
	 * Load default font or throw.
	 */
	public static function rses_load_default(): self {
		return new self( self::rses_default_path() );
	}

	public function rses_postscript_name(): string {
		return $this->rses_postscript_name;
	}

	/**
	 * @return array{0:int,1:int,2:int,3:int}
	 */
	public function rses_bbox(): array {
		return $this->rses_bbox;
	}

	public function rses_ascent(): int {
		return $this->rses_ascent;
	}

	public function rses_descent(): int {
		return $this->rses_descent;
	}

	public function rses_missing_width(): int {
		return $this->rses_missing_width;
	}

	/**
	 * Raw TTF bytes for FontFile2.
	 */
	public function rses_font_bytes(): string {
		return $this->rses_data;
	}

	/**
	 * Encode a UTF-8 string as a PDF hex string of 2-byte CIDs (glyph ids).
	 *
	 * @param string $utf8 UTF-8 text.
	 */
	public function rses_encode_text( string $utf8 ): string {
		$rses_codepoints = self::rses_utf8_codepoints( $utf8 );
		$rses_bin        = '';
		foreach ( $rses_codepoints as $rses_cp ) {
			if ( 0xA === $rses_cp || 0xD === $rses_cp ) {
				continue;
			}
			$rses_gid    = $this->rses_cmap[ $rses_cp ] ?? ( $this->rses_cmap[ 0x3F ] ?? 0 ); // '?' fallback
			$rses_bin   .= pack( 'n', $rses_gid & 0xFFFF );
		}
		return '<' . strtoupper( bin2hex( $rses_bin ) ) . '>';
	}

	/**
	 * Approximate rendered width of UTF-8 text at a font size (PDF points).
	 *
	 * @param string $utf8      UTF-8 text.
	 * @param float  $font_size Font size in points.
	 */
	public function rses_text_width( string $utf8, float $font_size ): float {
		$rses_units = 0;
		foreach ( self::rses_utf8_codepoints( $utf8 ) as $rses_cp ) {
			if ( 0xA === $rses_cp || 0xD === $rses_cp ) {
				continue;
			}
			$rses_gid    = $this->rses_cmap[ $rses_cp ] ?? ( $this->rses_cmap[ 0x3F ] ?? 0 );
			$rses_units += $this->rses_widths[ $rses_gid ] ?? $this->rses_missing_width;
		}
		return ( $rses_units * $font_size ) / 1000.0;
	}

	/**
	 * Truncate UTF-8 text to fit a max width, appending an ellipsis when needed.
	 *
	 * @param string $utf8      UTF-8 text.
	 * @param float  $font_size Font size.
	 * @param float  $max_width Max width in points.
	 */
	public function rses_fit_text( string $utf8, float $font_size, float $max_width ): string {
		if ( $this->rses_text_width( $utf8, $font_size ) <= $max_width ) {
			return $utf8;
		}
		$rses_ellipsis = '…';
		$rses_chars    = function_exists( 'mb_str_split' )
			? mb_str_split( $utf8, 1, 'UTF-8' )
			: preg_split( '//u', $utf8, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $rses_chars ) ) {
			return $utf8;
		}
		$rses_out = '';
		foreach ( $rses_chars as $rses_ch ) {
			$rses_try = $rses_out . $rses_ch . $rses_ellipsis;
			if ( $this->rses_text_width( $rses_try, $font_size ) > $max_width ) {
				break;
			}
			$rses_out .= $rses_ch;
		}
		return $rses_out . $rses_ellipsis;
	}

	/**
	 * Build /W array entries for glyphs used by the given UTF-8 lines.
	 *
	 * @param array<int,string> $lines UTF-8 lines.
	 * @return string PDF /W array body (without /W keyword).
	 */
	public function rses_widths_array_for_lines( array $lines ): string {
		$rses_gids = array();
		foreach ( $lines as $rses_line ) {
			foreach ( self::rses_utf8_codepoints( (string) $rses_line ) as $rses_cp ) {
				$rses_gid = $this->rses_cmap[ $rses_cp ] ?? ( $this->rses_cmap[ 0x3F ] ?? 0 );
				$rses_gids[ $rses_gid ] = true;
			}
		}
		$rses_gids[0] = true;
		$rses_list    = array_keys( $rses_gids );
		sort( $rses_list, SORT_NUMERIC );

		$rses_parts = array();
		$rses_run   = array();
		$rses_start = null;
		$rses_prev  = null;
		foreach ( $rses_list as $rses_gid ) {
			$rses_w = $this->rses_widths[ $rses_gid ] ?? $this->rses_missing_width;
			if ( null === $rses_start ) {
				$rses_start = $rses_gid;
				$rses_prev  = $rses_gid;
				$rses_run   = array( $rses_w );
				continue;
			}
			if ( $rses_gid === $rses_prev + 1 ) {
				$rses_run[] = $rses_w;
				$rses_prev  = $rses_gid;
				continue;
			}
			$rses_parts[] = $rses_start . ' [' . implode( ' ', $rses_run ) . ']';
			$rses_start   = $rses_gid;
			$rses_prev    = $rses_gid;
			$rses_run     = array( $rses_w );
		}
		if ( null !== $rses_start ) {
			$rses_parts[] = $rses_start . ' [' . implode( ' ', $rses_run ) . ']';
		}

		return '[ ' . implode( ' ', $rses_parts ) . ' ]';
	}

	/**
	 * Build a ToUnicode CMap stream covering codepoints used in lines.
	 *
	 * @param array<int,string> $lines UTF-8 lines.
	 */
	public function rses_tounicode_cmap( array $lines ): string {
		$rses_pairs = array();
		foreach ( $lines as $rses_line ) {
			foreach ( self::rses_utf8_codepoints( (string) $rses_line ) as $rses_cp ) {
				$rses_gid = $this->rses_cmap[ $rses_cp ] ?? ( $this->rses_cmap[ 0x3F ] ?? 0 );
				$rses_pairs[ $rses_gid ] = $rses_cp;
			}
		}
		ksort( $rses_pairs, SORT_NUMERIC );

		$rses_bf = '';
		$rses_n  = 0;
		$rses_chunks = array();
		foreach ( $rses_pairs as $rses_gid => $rses_cp ) {
			if ( $rses_cp > 0xFFFF ) {
				// Surrogate pair for planes above BMP.
				$rses_cp -= 0x10000;
				$rses_hi  = 0xD800 + ( $rses_cp >> 10 );
				$rses_lo  = 0xDC00 + ( $rses_cp & 0x3FF );
				$rses_uni = sprintf( '%04X%04X', $rses_hi, $rses_lo );
			} else {
				$rses_uni = sprintf( '%04X', $rses_cp );
			}
			$rses_bf .= sprintf( "<%04X> <%s>\n", $rses_gid, $rses_uni );
			++$rses_n;
			if ( 100 === $rses_n ) {
				$rses_chunks[] = $rses_bf;
				$rses_bf       = '';
				$rses_n        = 0;
			}
		}
		if ( '' !== $rses_bf ) {
			$rses_chunks[] = $rses_bf;
		}

		$rses_body = "/CIDInit /ProcSet findresource begin\n"
			. "12 dict begin\nbegincmap\n"
			. "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
			. "/CMapName /Adobe-Identity-UCS def\n"
			. "/CMapType 2 def\n"
			. "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";

		foreach ( $rses_chunks as $rses_chunk ) {
			$rses_count = substr_count( $rses_chunk, "\n" );
			$rses_body .= $rses_count . " beginbfchar\n" . $rses_chunk . "endbfchar\n";
		}

		$rses_body .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
		return $rses_body;
	}

	/**
	 * @return array<int,int>
	 */
	private static function rses_utf8_codepoints( string $utf8 ): array {
		if ( function_exists( 'mb_str_split' ) && function_exists( 'mb_ord' ) ) {
			$rses_chars = mb_str_split( $utf8, 1, 'UTF-8' );
			$rses_out   = array();
			foreach ( $rses_chars as $rses_ch ) {
				$rses_ord = mb_ord( $rses_ch, 'UTF-8' );
				if ( false !== $rses_ord ) {
					$rses_out[] = $rses_ord;
				}
			}
			return $rses_out;
		}

		$rses_out = array();
		$rses_len = strlen( $utf8 );
		for ( $rses_i = 0; $rses_i < $rses_len; ) {
			$rses_c = ord( $utf8[ $rses_i ] );
			if ( $rses_c < 0x80 ) {
				$rses_out[] = $rses_c;
				++$rses_i;
			} elseif ( ( $rses_c & 0xE0 ) === 0xC0 && $rses_i + 1 < $rses_len ) {
				$rses_out[] = ( ( $rses_c & 0x1F ) << 6 ) | ( ord( $utf8[ $rses_i + 1 ] ) & 0x3F );
				$rses_i    += 2;
			} elseif ( ( $rses_c & 0xF0 ) === 0xE0 && $rses_i + 2 < $rses_len ) {
				$rses_out[] = ( ( $rses_c & 0x0F ) << 12 )
					| ( ( ord( $utf8[ $rses_i + 1 ] ) & 0x3F ) << 6 )
					| ( ord( $utf8[ $rses_i + 2 ] ) & 0x3F );
				$rses_i += 3;
			} elseif ( ( $rses_c & 0xF8 ) === 0xF0 && $rses_i + 3 < $rses_len ) {
				$rses_out[] = ( ( $rses_c & 0x07 ) << 18 )
					| ( ( ord( $utf8[ $rses_i + 1 ] ) & 0x3F ) << 12 )
					| ( ( ord( $utf8[ $rses_i + 2 ] ) & 0x3F ) << 6 )
					| ( ord( $utf8[ $rses_i + 3 ] ) & 0x3F );
				$rses_i += 4;
			} else {
				$rses_out[] = 0x3F;
				++$rses_i;
			}
		}
		return $rses_out;
	}

	private function rses_parse(): void {
		$rses_tables = $this->rses_read_table_directory();

		if ( isset( $rses_tables['name'] ) ) {
			$this->rses_parse_name( $rses_tables['name'] );
		}
		if ( isset( $rses_tables['head'] ) ) {
			$this->rses_parse_head( $rses_tables['head'] );
		}
		if ( isset( $rses_tables['hhea'] ) ) {
			$this->rses_parse_hhea( $rses_tables['hhea'] );
		}

		$rses_num_glyphs = 0;
		if ( isset( $rses_tables['maxp'] ) ) {
			$rses_num_glyphs = $this->rses_u16( $rses_tables['maxp']['offset'] + 4 );
		}

		if ( isset( $rses_tables['hmtx'] ) && isset( $rses_tables['hhea'] ) ) {
			$this->rses_parse_hmtx( $rses_tables['hmtx'], $rses_tables['hhea'], $rses_num_glyphs );
		}
		if ( isset( $rses_tables['cmap'] ) ) {
			$this->rses_parse_cmap( $rses_tables['cmap'] );
		}

		if ( empty( $this->rses_cmap ) ) {
			throw new \RuntimeException( 'TTF has no usable Unicode cmap.' );
		}
	}

	/**
	 * @return array<string,array{offset:int,length:int}>
	 */
	private function rses_read_table_directory(): array {
		$rses_num = $this->rses_u16( 4 );
		$rses_out = array();
		for ( $rses_i = 0; $rses_i < $rses_num; ++$rses_i ) {
			$rses_off  = 12 + ( $rses_i * 16 );
			$rses_tag  = substr( $this->rses_data, $rses_off, 4 );
			$rses_to   = $this->rses_u32( $rses_off + 8 );
			$rses_len  = $this->rses_u32( $rses_off + 12 );
			$rses_out[ $rses_tag ] = array(
				'offset' => $rses_to,
				'length' => $rses_len,
			);
		}
		return $rses_out;
	}

	/**
	 * @param array{offset:int,length:int} $table Table.
	 */
	private function rses_parse_name( array $table ): void {
		$rses_base     = $table['offset'];
		$rses_count    = $this->rses_u16( $rses_base + 2 );
		$rses_string_o = $rses_base + $this->rses_u16( $rses_base + 4 );
		for ( $rses_i = 0; $rses_i < $rses_count; ++$rses_i ) {
			$rses_rec = $rses_base + 6 + ( $rses_i * 12 );
			$rses_plat = $this->rses_u16( $rses_rec );
			$rses_enc  = $this->rses_u16( $rses_rec + 2 );
			$rses_name = $this->rses_u16( $rses_rec + 6 );
			$rses_len  = $this->rses_u16( $rses_rec + 8 );
			$rses_soff = $this->rses_u16( $rses_rec + 10 );
			if ( 6 !== $rses_name ) { // PostScript name.
				continue;
			}
			$rses_raw = substr( $this->rses_data, $rses_string_o + $rses_soff, $rses_len );
			if ( ( 0 === $rses_plat || 3 === $rses_plat ) && 1 === $rses_enc ) {
				$rses_ps = @mb_convert_encoding( $rses_raw, 'UTF-8', 'UTF-16BE' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				$rses_ps = $rses_raw;
			}
			if ( is_string( $rses_ps ) && '' !== $rses_ps ) {
				$this->rses_postscript_name = preg_replace( '/[^A-Za-z0-9_-]/', '', $rses_ps ) ?: 'EmbeddedSans';
				return;
			}
		}
	}

	/**
	 * @param array{offset:int,length:int} $table Table.
	 */
	private function rses_parse_head( array $table ): void {
		$rses_o                   = $table['offset'];
		$this->rses_units_per_em  = max( 1, $this->rses_u16( $rses_o + 18 ) );
		$rses_x_min               = $this->rses_i16( $rses_o + 36 );
		$rses_y_min               = $this->rses_i16( $rses_o + 38 );
		$rses_x_max               = $this->rses_i16( $rses_o + 40 );
		$rses_y_max               = $this->rses_i16( $rses_o + 42 );
		$rses_scale               = 1000 / $this->rses_units_per_em;
		$this->rses_bbox          = array(
			(int) round( $rses_x_min * $rses_scale ),
			(int) round( $rses_y_min * $rses_scale ),
			(int) round( $rses_x_max * $rses_scale ),
			(int) round( $rses_y_max * $rses_scale ),
		);
	}

	/**
	 * @param array{offset:int,length:int} $table Table.
	 */
	private function rses_parse_hhea( array $table ): void {
		$rses_o              = $table['offset'];
		$rses_scale          = 1000 / $this->rses_units_per_em;
		$this->rses_ascent   = (int) round( $this->rses_i16( $rses_o + 4 ) * $rses_scale );
		$this->rses_descent  = (int) round( $this->rses_i16( $rses_o + 6 ) * $rses_scale );
	}

	/**
	 * @param array{offset:int,length:int} $hmtx       hmtx table.
	 * @param array{offset:int,length:int} $hhea       hhea table.
	 * @param int                          $num_glyphs Glyph count.
	 */
	private function rses_parse_hmtx( array $hmtx, array $hhea, int $num_glyphs ): void {
		$rses_number_of_metrics = $this->rses_u16( $hhea['offset'] + 34 );
		$rses_scale             = 1000 / $this->rses_units_per_em;
		$rses_o                 = $hmtx['offset'];
		$rses_last_width        = 0;
		for ( $rses_i = 0; $rses_i < $rses_number_of_metrics; ++$rses_i ) {
			$rses_w                      = $this->rses_u16( $rses_o + ( $rses_i * 4 ) );
			$rses_last_width             = $rses_w;
			$this->rses_widths[ $rses_i ] = (int) round( $rses_w * $rses_scale );
		}
		$this->rses_missing_width = (int) round( $rses_last_width * $rses_scale );
		for ( $rses_i = $rses_number_of_metrics; $rses_i < $num_glyphs; ++$rses_i ) {
			$this->rses_widths[ $rses_i ] = $this->rses_missing_width;
		}
	}

	/**
	 * @param array{offset:int,length:int} $table cmap table.
	 */
	private function rses_parse_cmap( array $table ): void {
		$rses_base  = $table['offset'];
		$rses_num   = $this->rses_u16( $rses_base + 2 );
		$rses_fmt4  = null;
		for ( $rses_i = 0; $rses_i < $rses_num; ++$rses_i ) {
			$rses_rec  = $rses_base + 4 + ( $rses_i * 8 );
			$rses_plat = $this->rses_u16( $rses_rec );
			$rses_enc  = $this->rses_u16( $rses_rec + 2 );
			$rses_off  = $this->rses_u32( $rses_rec + 4 );
			$rses_abs  = $rses_base + $rses_off;
			$rses_fmt  = $this->rses_u16( $rses_abs );
			if ( 4 === $rses_fmt && ( ( 3 === $rses_plat && 1 === $rses_enc ) || 0 === $rses_plat ) ) {
				$rses_fmt4 = $rses_abs;
				if ( 3 === $rses_plat && 1 === $rses_enc ) {
					break;
				}
			}
		}
		if ( null === $rses_fmt4 ) {
			return;
		}
		$this->rses_parse_cmap_format4( $rses_fmt4 );
	}

	private function rses_parse_cmap_format4( int $offset ): void {
		$rses_seg_count = (int) ( $this->rses_u16( $offset + 6 ) / 2 );
		$rses_end_o     = $offset + 14;
		$rses_start_o   = $rses_end_o + ( 2 * $rses_seg_count ) + 2;
		$rses_delta_o   = $rses_start_o + ( 2 * $rses_seg_count );
		$rses_range_o   = $rses_delta_o + ( 2 * $rses_seg_count );
		$rses_glyph_o   = $rses_range_o + ( 2 * $rses_seg_count );

		for ( $rses_i = 0; $rses_i < $rses_seg_count; ++$rses_i ) {
			$rses_end   = $this->rses_u16( $rses_end_o + ( 2 * $rses_i ) );
			$rses_start = $this->rses_u16( $rses_start_o + ( 2 * $rses_i ) );
			$rses_delta = $this->rses_i16( $rses_delta_o + ( 2 * $rses_i ) );
			$rses_roff  = $this->rses_u16( $rses_range_o + ( 2 * $rses_i ) );
			for ( $rses_c = $rses_start; $rses_c <= $rses_end; ++$rses_c ) {
				if ( 0 === $rses_roff ) {
					$rses_gid = ( $rses_c + $rses_delta ) & 0xFFFF;
				} else {
					$rses_pos = $rses_range_o + ( 2 * $rses_i ) + $rses_roff + ( 2 * ( $rses_c - $rses_start ) );
					$rses_gid = $this->rses_u16( $rses_pos );
					if ( 0 !== $rses_gid ) {
						$rses_gid = ( $rses_gid + $rses_delta ) & 0xFFFF;
					}
				}
				if ( $rses_gid > 0 || 0 === $rses_c ) {
					$this->rses_cmap[ $rses_c ] = $rses_gid;
				}
				if ( 0xFFFF === $rses_c ) {
					break;
				}
			}
		}
	}

	private function rses_u16( int $offset ): int {
		$rses_u = unpack( 'n', substr( $this->rses_data, $offset, 2 ) );
		return (int) ( $rses_u[1] ?? 0 );
	}

	private function rses_i16( int $offset ): int {
		$rses_v = $this->rses_u16( $offset );
		return $rses_v > 0x7FFF ? $rses_v - 0x10000 : $rses_v;
	}

	private function rses_u32( int $offset ): int {
		$rses_u = unpack( 'N', substr( $this->rses_data, $offset, 4 ) );
		return (int) ( $rses_u[1] ?? 0 );
	}
}
