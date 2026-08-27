<?php
/**
 * Styled certification PDF matching the Tallying Certification UI.
 *
 * @package RelataSoft\SecureElectionSuite\Exports
 */

namespace RelataSoft\SecureElectionSuite\Exports;

use RelataSoft\SecureElectionSuite\Tallying\DecryptedResultsPresenter;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a multi-page UTF-8 PDF: hero, status, result tables with vote bars, completion stamp, optional signed JSON.
 */
class CertificationPdf {

	private const RSES_PAGE_W = 612.0;
	private const RSES_PAGE_H = 792.0;
	private const RSES_MARGIN = 40.0;

	/** @var PdfTrueTypeFont */
	private PdfTrueTypeFont $rses_font;

	/** @var array<int,string> Content streams per page. */
	private array $rses_streams = array();

	/** @var array<int,string> All UTF-8 snippets used (for widths / ToUnicode). */
	private array $rses_used_strings = array();

	/** @var int */
	private int $rses_page = 0;

	/** @var float */
	private float $rses_y = 0.0;

	/**
	 * @param array<string,mixed>      $report    Report meta (election, hashes, …).
	 * @param array<string,mixed>      $humanized Humanized results.
	 * @param array<string,mixed>|null $package   Optional results-signed package to append.
	 */
	public static function rses_generate( array $report, array $humanized, ?array $package = null ): string {
		$rses_builder = new self();
		return $rses_builder->rses_build( $report, $humanized, $package );
	}

	private function __construct() {
		$this->rses_font = PdfTrueTypeFont::rses_load_default();
	}

	/**
	 * @param array<string,mixed>      $report    Meta.
	 * @param array<string,mixed>      $humanized Humanized.
	 * @param array<string,mixed>|null $package   Signed embed.
	 */
	private function rses_build( array $report, array $humanized, ?array $package ): string {
		$this->rses_new_page( false );
		$this->rses_draw_hero();
		$this->rses_draw_signed_banner( $report );
		$this->rses_draw_legal_declaration();
		$this->rses_draw_election_card( $report, $humanized );
		$this->rses_draw_results( $humanized );
		$this->rses_draw_completed_stamp();
		if ( is_array( $package ) ) {
			$this->rses_draw_signed_json( $package );
		}
		return $this->rses_assemble();
	}

	private function rses_new_page( bool $with_top_margin = true ): void {
		if ( empty( $this->rses_streams ) ) {
			$this->rses_page             = 0;
			$this->rses_streams[0]       = '';
		} else {
			++$this->rses_page;
			$this->rses_streams[ $this->rses_page ] = '';
		}
		$this->rses_y = $with_top_margin ? ( self::RSES_PAGE_H - self::RSES_MARGIN ) : self::RSES_PAGE_H;
	}

	private function rses_ensure_space( float $needed ): void {
		if ( $this->rses_y - $needed < self::RSES_MARGIN ) {
			$this->rses_new_page( true );
		}
	}

	/**
	 * @param array{0:float,1:float,2:float} $rgb RGB 0–1.
	 */
	private function rses_rgb( array $rgb ): string {
		return sprintf( '%.3F %.3F %.3F', $rgb[0], $rgb[1], $rgb[2] );
	}

	/**
	 * @param array{0:float,1:float,2:float} $rgb Color.
	 */
	private function rses_fill_rect( float $x, float $y, float $w, float $h, array $rgb ): void {
		$this->rses_streams[ $this->rses_page ] .= sprintf(
			"q %s rg %.2F %.2F %.2F %.2F re f Q\n",
			$this->rses_rgb( $rgb ),
			$x,
			$y,
			$w,
			$h
		);
	}

	/**
	 * @param array{0:float,1:float,2:float} $rgb Color.
	 */
	private function rses_stroke_rect( float $x, float $y, float $w, float $h, array $rgb, float $lw = 1.0 ): void {
		$this->rses_streams[ $this->rses_page ] .= sprintf(
			"q %.2F w %s RG %.2F %.2F %.2F %.2F re S Q\n",
			$lw,
			$this->rses_rgb( $rgb ),
			$x,
			$y,
			$w,
			$h
		);
	}

	/**
	 * @param array{0:float,1:float,2:float} $rgb Color.
	 */
	private function rses_text( float $x, float $y, string $text, float $size, array $rgb ): void {
		$this->rses_used_strings[] = $text;
		$rses_hex                  = $this->rses_font->rses_encode_text( $text );
		$this->rses_streams[ $this->rses_page ] .= sprintf(
			"q BT /F1 %.2F Tf %s rg 1 0 0 1 %.2F %.2F Tm %s Tj ET Q\n",
			$size,
			$this->rses_rgb( $rgb ),
			$x,
			$y,
			$rses_hex
		);
	}

	private function rses_draw_hero(): void {
		$rses_navy = array( 0.043, 0.071, 0.188 );
		$rses_gold = array( 1.0, 0.722, 0.0 );
		$rses_ink  = array( 0.969, 0.957, 0.918 );
		$rses_mute = array( 0.85, 0.82, 0.75 );
		$rses_h    = 138.0;
		$rses_y0   = self::RSES_PAGE_H - $rses_h;
		$this->rses_fill_rect( 0, $rses_y0, self::RSES_PAGE_W, $rses_h, $rses_navy );

		$this->rses_text( self::RSES_MARGIN, $rses_y0 + 108, 'RelataSoft', 11, $rses_gold );
		$this->rses_text(
			self::RSES_MARGIN,
			$rses_y0 + 90,
			__( 'Voto Eletrônico by RelataSoft', 'relatasoft-secure-election-suite' ),
			9,
			$rses_mute
		);
		$this->rses_text(
			self::RSES_MARGIN,
			$rses_y0 + 68,
			self::rses_upper( __( 'Tallying', 'relatasoft-secure-election-suite' ) ),
			10,
			$rses_gold
		);
		$this->rses_text(
			self::RSES_MARGIN,
			$rses_y0 + 42,
			__( 'Certification', 'relatasoft-secure-election-suite' ),
			22,
			$rses_ink
		);
		$rses_lead = __( 'Review decrypted tallies, generate certification records, and export ZIP or PDF packages.', 'relatasoft-secure-election-suite' );
		$rses_lead = $this->rses_font->rses_fit_text( $rses_lead, 8.5, self::RSES_PAGE_W - ( 2 * self::RSES_MARGIN ) );
		$this->rses_text( self::RSES_MARGIN, $rses_y0 + 18, $rses_lead, 8.5, $rses_mute );

		$this->rses_y = $rses_y0 - 16;
	}

	/**
	 * @param array<string,mixed> $report Meta.
	 */
	private function rses_draw_signed_banner( array $report ): void {
		$rses_bg   = array( 0.890, 0.961, 0.890 );
		$rses_ink  = array( 0.102, 0.400, 0.102 );
		$rses_line = array( 0.776, 0.918, 0.776 );
		$rses_x    = self::RSES_MARGIN;
		$rses_w    = self::RSES_PAGE_W - ( 2 * self::RSES_MARGIN );

		$rses_fp = (string) ( $report['public_key_fingerprint'] ?? '' );
		if ( '' === $rses_fp && ! empty( $report['public_key_hash'] ) ) {
			$rses_fp = substr( (string) $report['public_key_hash'], 0, 12 );
		}
		$rses_detail = sprintf(
			/* translators: 1: fingerprint, 2: scheme */
			__( 'Schnorr signature under election public key (fp %1$s), scheme %2$s. Anyone with the signed JSON can verify without trusting this server.', 'relatasoft-secure-election-suite' ),
			'' !== $rses_fp ? $rses_fp : '—',
			(string) ( $report['signature_scheme'] ?? 'schnorr-sha256-modpq-v1' )
		);
		$rses_detail_lines = $this->rses_wrap_lines( $rses_detail, 8.5, $rses_w - 24, 3 );
		$rses_h            = 28.0 + ( count( $rses_detail_lines ) * 11.0 );
		$this->rses_ensure_space( $rses_h + 12 );
		$rses_y = $this->rses_y - $rses_h;
		$this->rses_fill_rect( $rses_x, $rses_y, $rses_w, $rses_h, $rses_bg );
		$this->rses_stroke_rect( $rses_x, $rses_y, $rses_w, $rses_h, $rses_line, 0.8 );

		$this->rses_text( $rses_x + 12, $rses_y + $rses_h - 18, __( 'Digitally signed', 'relatasoft-secure-election-suite' ), 11, $rses_ink );
		$rses_ty = $rses_y + $rses_h - 34;
		foreach ( $rses_detail_lines as $rses_detail_line ) {
			$this->rses_text( $rses_x + 12, $rses_ty, $rses_detail_line, 8.5, $rses_ink );
			$rses_ty -= 11;
		}
		$this->rses_y = $rses_y - 14;
	}

	/**
	 * Short legal / administrative declaration (Shamir threshold + dual Schnorr).
	 */
	private function rses_draw_legal_declaration(): void {
		$rses_bg   = array( 0.965, 0.969, 0.976 );
		$rses_ink  = array( 0.110, 0.165, 0.196 );
		$rses_mute = array( 0.353, 0.420, 0.459 );
		$rses_line = array( 0.780, 0.804, 0.835 );
		$rses_x    = self::RSES_MARGIN;
		$rses_w    = self::RSES_PAGE_W - ( 2 * self::RSES_MARGIN );

		$rses_title = __( 'Legal declaration', 'relatasoft-secure-election-suite' );
		$rses_body  = __(
			'These results were obtained by reconstructing the election private key under a Shamir secret-sharing threshold from official share parcels, then decrypting the homomorphic tally. The results JSON is Schnorr-signed under the election ElGamal key; a second Schnorr signature binds the PDF bytes (pdf_sha256). Independent auditors may verify both signatures with the embedded public key alone.',
			'relatasoft-secure-election-suite'
		);
		$rses_lines = $this->rses_wrap_lines( $rses_body, 8.5, $rses_w - 24, 6 );
		$rses_h     = 28.0 + ( count( $rses_lines ) * 11.0 );
		$this->rses_ensure_space( $rses_h + 12 );
		$rses_y = $this->rses_y - $rses_h;
		$this->rses_fill_rect( $rses_x, $rses_y, $rses_w, $rses_h, $rses_bg );
		$this->rses_stroke_rect( $rses_x, $rses_y, $rses_w, $rses_h, $rses_line, 0.8 );
		$this->rses_text( $rses_x + 12, $rses_y + $rses_h - 18, $rses_title, 11, $rses_ink );
		$rses_ty = $rses_y + $rses_h - 34;
		foreach ( $rses_lines as $rses_line ) {
			$this->rses_text( $rses_x + 12, $rses_ty, $rses_line, 8.5, $rses_mute );
			$rses_ty -= 11;
		}
		$this->rses_y = $rses_y - 14;
	}

	/**
	 * @param array<string,mixed> $report    Meta.
	 * @param array<string,mixed> $humanized Humanized.
	 */
	private function rses_draw_election_card( array $report, array $humanized ): void {
		$rses_bg   = array( 1.0, 0.969, 0.871 );
		$rses_ink  = array( 0.110, 0.165, 0.196 );
		$rses_mute = array( 0.353, 0.420, 0.459 );
		$rses_line = array( 0.941, 0.835, 0.604 );
		$rses_ok   = array( 0.102, 0.400, 0.102 );
		$rses_h    = 78.0;
		$this->rses_ensure_space( $rses_h + 12 );
		$rses_x = self::RSES_MARGIN;
		$rses_w = self::RSES_PAGE_W - ( 2 * self::RSES_MARGIN );
		$rses_y = $this->rses_y - $rses_h;
		$this->rses_fill_rect( $rses_x, $rses_y, $rses_w, $rses_h, $rses_bg );
		$this->rses_stroke_rect( $rses_x, $rses_y, $rses_w, $rses_h, $rses_line, 0.8 );

		$rses_title = $this->rses_font->rses_fit_text( (string) ( $report['election_title'] ?? '' ), 13, $rses_w - 100 );
		$this->rses_text( $rses_x + 12, $rses_y + 54, $rses_title, 13, $rses_ink );

		$rses_status = $this->rses_status_label( (string) ( $report['verification_status'] ?? 'verified' ) );
		$rses_status = self::rses_upper( $rses_status );
		$rses_sw     = $this->rses_font->rses_text_width( $rses_status, 8 ) + 14;
		$rses_sx     = $rses_x + $rses_w - $rses_sw - 12;
		$this->rses_fill_rect( $rses_sx, $rses_y + 48, $rses_sw, 16, array( 0.890, 0.961, 0.890 ) );
		$this->rses_text( $rses_sx + 7, $rses_y + 52, $rses_status, 8, $rses_ok );

		$rses_round = trim( (string) ( $report['round_title'] ?? $report['round_number'] ?? '' ) );
		$rses_meta  = sprintf(
			'%s %s · %s',
			__( 'Round:', 'relatasoft-secure-election-suite' ),
			'' !== $rses_round ? $rses_round : '—',
			sprintf(
				/* translators: %d: ballot count */
				__( '%d ballots', 'relatasoft-secure-election-suite' ),
				(int) ( $report['ballot_count'] ?? 0 )
			)
		);
		$this->rses_text( $rses_x + 12, $rses_y + 34, $this->rses_font->rses_fit_text( $rses_meta, 9, $rses_w - 24 ), 9, $rses_mute );

		$rses_modes = DecryptedResultsPresenter::rses_sort_modes();
		$rses_sort  = (string) ( $humanized['sort'] ?? DecryptedResultsPresenter::RSES_SORT_COUNT_DESC );
		$rses_order = __( 'Results order:', 'relatasoft-secure-election-suite' ) . ' ' . ( $rses_modes[ $rses_sort ] ?? $rses_sort );
		$this->rses_text( $rses_x + 12, $rses_y + 16, $this->rses_font->rses_fit_text( $rses_order, 8.5, $rses_w - 24 ), 8.5, $rses_mute );

		$this->rses_y = $rses_y - 16;
	}

	/**
	 * @param array<string,mixed> $humanized Humanized.
	 */
	private function rses_draw_results( array $humanized ): void {
		$rses_ink   = array( 0.110, 0.165, 0.196 );
		$rses_mute  = array( 0.353, 0.420, 0.459 );
		$rses_line  = array( 0.788, 0.839, 0.863 );
		$rses_head  = array( 0.969, 0.984, 0.988 );
		$rses_white = array( 1, 1, 1 );
		$rses_bar_bg = array( 0.925, 0.941, 0.945 );
		$rses_bar_fg = array( 0.961, 0.651, 0.137 );
		$rses_bar_lo = array( 0.820, 0.855, 0.875 );

		$rses_x = self::RSES_MARGIN;
		$rses_w = self::RSES_PAGE_W - ( 2 * self::RSES_MARGIN );

		// Column layout inside table (offsets + widths within content box).
		$rses_cols = array(
			'rank'   => array( 0, 48 ),
			'option' => array( 48, 250 ),
			'votes'  => array( 298, 78 ),
			'share'  => array( 376, 78 ),
			'id'     => array( 454, 78 ),
		);

		foreach ( $humanized['questions'] ?? array() as $rses_q ) {
			if ( ! is_array( $rses_q ) ) {
				continue;
			}
			$rses_opts = is_array( $rses_q['options'] ?? null ) ? $rses_q['options'] : array();
			$rses_rows = count( $rses_opts );
			$rses_block = 28 + 16 + 22 + ( $rses_rows * 36 ) + 8;
			$this->rses_ensure_space( min( $rses_block, 200 ) );

			// Question title.
			$rses_qtitle = (string) ( $rses_q['question_title'] ?? '' );
			$rses_qid    = (int) ( $rses_q['question_id'] ?? 0 );
			$this->rses_text( $rses_x, $this->rses_y - 14, $this->rses_font->rses_fit_text( $rses_qtitle, 12, $rses_w - 50 ), 12, $rses_ink );
			$this->rses_text( $rses_x + $rses_w - 36, $this->rses_y - 14, '#' . $rses_qid, 10, $rses_mute );
			$this->rses_y -= 28;

			$rses_total = sprintf(
				/* translators: %d: total votes */
				__( 'Total votes: %d', 'relatasoft-secure-election-suite' ),
				(int) ( $rses_q['total_votes'] ?? 0 )
			);
			$this->rses_text( $rses_x, $this->rses_y - 10, $rses_total, 9, $rses_mute );
			$this->rses_y -= 18;

			// Table header.
			$rses_th_h = 22.0;
			$this->rses_ensure_space( $rses_th_h + 40 );
			$rses_ty = $this->rses_y - $rses_th_h;
			$this->rses_fill_rect( $rses_x, $rses_ty, $rses_w, $rses_th_h, $rses_head );
			$this->rses_stroke_rect( $rses_x, $rses_ty, $rses_w, $rses_th_h, $rses_line, 0.6 );
			$rses_headers = array(
				'rank'   => self::rses_upper( __( 'Rank', 'relatasoft-secure-election-suite' ) ),
				'option' => self::rses_upper( __( 'Option', 'relatasoft-secure-election-suite' ) ),
				'votes'  => self::rses_upper( __( 'Votes', 'relatasoft-secure-election-suite' ) ),
				'share'  => self::rses_upper( __( 'Share', 'relatasoft-secure-election-suite' ) ),
				'id'     => 'ID',
			);
			foreach ( $rses_headers as $rses_key => $rses_label ) {
				$rses_cx = $rses_x + $rses_cols[ $rses_key ][0] + 6;
				$this->rses_text( $rses_cx, $rses_ty + 7, $rses_label, 7.5, $rses_mute );
			}
			$this->rses_y = $rses_ty;

			foreach ( $rses_opts as $rses_i => $rses_opt ) {
				if ( ! is_array( $rses_opt ) ) {
					continue;
				}
				$rses_rh = 36.0;
				$this->rses_ensure_space( $rses_rh + 4 );
				$rses_ry = $this->rses_y - $rses_rh;
				$this->rses_fill_rect( $rses_x, $rses_ry, $rses_w, $rses_rh, $rses_white );
				$this->rses_stroke_rect( $rses_x, $rses_ry, $rses_w, $rses_rh, $rses_line, 0.5 );

				$rses_rank  = (string) (int) ( $rses_opt['rank'] ?? 0 );
				$rses_label = (string) ( $rses_opt['option_label'] ?? '' );
				$rses_votes  = number_format_i18n( (int) ( $rses_opt['count'] ?? 0 ) );
				$rses_pct   = (float) ( $rses_opt['share_pct'] ?? 0 );
				$rses_pct_s = number_format_i18n( $rses_pct, 2 ) . '%';
				$rses_oid   = null !== ( $rses_opt['option_id'] ?? null ) ? (string) (int) $rses_opt['option_id'] : '—';

				$this->rses_text( $rses_x + $rses_cols['rank'][0] + 10, $rses_ry + 20, $rses_rank, 10, $rses_ink );
				$rses_olabel = $this->rses_font->rses_fit_text( $rses_label, 10, $rses_cols['option'][1] - 16 );
				$this->rses_text( $rses_x + $rses_cols['option'][0] + 6, $rses_ry + 22, $rses_olabel, 10, $rses_ink );

				// Vote bar.
				$rses_bar_x = $rses_x + $rses_cols['option'][0] + 6;
				$rses_bar_w = $rses_cols['option'][1] - 16;
				$rses_bar_y = $rses_ry + 8;
				$rses_bar_h = 5.0;
				$this->rses_fill_rect( $rses_bar_x, $rses_bar_y, $rses_bar_w, $rses_bar_h, $rses_bar_bg );
				$rses_fill_w = max( 0.0, min( $rses_bar_w, $rses_bar_w * ( $rses_pct / 100.0 ) ) );
				if ( $rses_fill_w > 0 ) {
					$rses_color = ( 0 === $rses_i ) ? $rses_bar_fg : $rses_bar_lo;
					$this->rses_fill_rect( $rses_bar_x, $rses_bar_y, $rses_fill_w, $rses_bar_h, $rses_color );
				}

				$this->rses_text( $rses_x + $rses_cols['votes'][0] + 6, $rses_ry + 14, $rses_votes, 10, $rses_ink );
				$this->rses_text( $rses_x + $rses_cols['share'][0] + 6, $rses_ry + 14, $rses_pct_s, 9, $rses_mute );

				$rses_id_w = $this->rses_font->rses_text_width( $rses_oid, 8 ) + 12;
				$rses_id_x = $rses_x + $rses_cols['id'][0] + 8;
				$this->rses_fill_rect( $rses_id_x, $rses_ry + 10, $rses_id_w, 14, array( 0.925, 0.941, 0.945 ) );
				$this->rses_text( $rses_id_x + 6, $rses_ry + 13, $rses_oid, 8, $rses_mute );

				$this->rses_y = $rses_ry;
			}

			$this->rses_y -= 14;
		}
	}

	private function rses_draw_completed_stamp(): void {
		$rses_ink  = array( 0.110, 0.165, 0.196 );
		$rses_mute = array( 0.353, 0.420, 0.459 );
		$this->rses_ensure_space( 40 );
		$this->rses_y -= 8;

		$rses_when = $this->rses_site_datetime();
		$rses_line = __( 'Completed at', 'relatasoft-secure-election-suite' ) . ' ' . $rses_when;
		$this->rses_text( self::RSES_MARGIN, $this->rses_y - 12, $rses_line, 10, $rses_ink );
		$this->rses_y -= 28;

		$rses_tz = $this->rses_timezone_label();
		if ( '' !== $rses_tz ) {
			$this->rses_text(
				self::RSES_MARGIN,
				$this->rses_y - 10,
				sprintf(
					/* translators: %s: timezone name/offset */
					__( 'Site timezone: %s', 'relatasoft-secure-election-suite' ),
					$rses_tz
				),
				8,
				$rses_mute
			);
			$this->rses_y -= 20;
		}
	}

	/**
	 * @param array<string,mixed> $package Signed package.
	 */
	private function rses_draw_signed_json( array $package ): void {
		$rses_ink   = array( 0.110, 0.165, 0.196 );
		$rses_mute  = array( 0.353, 0.420, 0.459 );
		$rses_bg    = array( 0.969, 0.984, 0.988 );
		$rses_border = array( 0.788, 0.839, 0.863 );

		$this->rses_ensure_space( 80 );
		$this->rses_y -= 6;
		$this->rses_text( self::RSES_MARGIN, $this->rses_y - 12, __( 'Signed results JSON', 'relatasoft-secure-election-suite' ), 12, $rses_ink );
		$this->rses_y -= 20;
		$rses_note = __( 'Schnorr-signed package (results). The downloadable signed-results.json also binds this PDF’s SHA-256.', 'relatasoft-secure-election-suite' );
		$rses_note = $this->rses_font->rses_fit_text( $rses_note, 8, self::RSES_PAGE_W - ( 2 * self::RSES_MARGIN ) );
		$this->rses_text( self::RSES_MARGIN, $this->rses_y - 10, $rses_note, 8, $rses_mute );
		$this->rses_y -= 18;

		$rses_json = wp_json_encode( $package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $rses_json ) ) {
			$rses_json = '{}';
		}
		$rses_json_lines = explode( "\n", $rses_json );
		$rses_x          = self::RSES_MARGIN;
		$rses_w          = self::RSES_PAGE_W - ( 2 * self::RSES_MARGIN );
		$rses_size       = 7.0;
		$rses_lh         = 9.0;

		foreach ( $rses_json_lines as $rses_json_line ) {
			foreach ( $this->rses_wrap_lines( $rses_json_line, $rses_size, $rses_w - 16 ) as $rses_chunk ) {
				$this->rses_ensure_space( $rses_lh + 2 );
				$rses_ry = $this->rses_y - $rses_lh;
				$this->rses_fill_rect( $rses_x, $rses_ry, $rses_w, $rses_lh, $rses_bg );
				$this->rses_text( $rses_x + 6, $rses_ry + 2, $rses_chunk, $rses_size, $rses_ink );
				$this->rses_y = $rses_ry;
			}
		}
		$this->rses_stroke_rect( $rses_x, $this->rses_y, $rses_w, 0.01, $rses_border, 0.4 );
	}

	/**
	 * Soft-wrap UTF-8 text to a max width (no ellipsis).
	 *
	 * @return list<string>
	 */
	private function rses_wrap_lines( string $text, float $size, float $max_width, int $max_lines = 0 ): array {
		if ( '' === $text ) {
			return array( '' );
		}
		if ( $this->rses_font->rses_text_width( $text, $size ) <= $max_width ) {
			return array( $text );
		}

		$rses_words = preg_split( '/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $rses_words ) || empty( $rses_words ) ) {
			return array( $text );
		}

		$rses_lines = array();
		$rses_cur   = '';
		foreach ( $rses_words as $rses_word ) {
			$rses_try = $rses_cur . $rses_word;
			if ( '' !== $rses_cur && $this->rses_font->rses_text_width( $rses_try, $size ) > $max_width ) {
				$rses_lines[] = rtrim( $rses_cur );
				$rses_cur     = ltrim( $rses_word );
				if ( $max_lines > 0 && count( $rses_lines ) >= $max_lines ) {
					break;
				}
			} else {
				$rses_cur = $rses_try;
			}
		}
		if ( $max_lines > 0 && count( $rses_lines ) >= $max_lines ) {
			// Truncate leftover into the last allowed line.
			if ( count( $rses_lines ) === $max_lines ) {
				$rses_lines[ $max_lines - 1 ] = $this->rses_font->rses_fit_text(
					$rses_lines[ $max_lines - 1 ] . ( '' !== $rses_cur ? ' ' . $rses_cur : '' ),
					$size,
					$max_width
				);
			}
			return $rses_lines;
		}
		if ( '' !== $rses_cur ) {
			$rses_lines[] = rtrim( $rses_cur );
		}

		// Hard-break any still-too-wide line (e.g. long tokens).
		$rses_out = array();
		foreach ( $rses_lines as $rses_line ) {
			if ( $this->rses_font->rses_text_width( $rses_line, $size ) <= $max_width ) {
				$rses_out[] = $rses_line;
				continue;
			}
			$rses_chars = function_exists( 'mb_str_split' )
				? mb_str_split( $rses_line, 1, 'UTF-8' )
				: preg_split( '//u', $rses_line, -1, PREG_SPLIT_NO_EMPTY );
			if ( ! is_array( $rses_chars ) ) {
				$rses_out[] = $rses_line;
				continue;
			}
			$rses_take = '';
			foreach ( $rses_chars as $rses_ch ) {
				$rses_try = $rses_take . $rses_ch;
				if ( '' !== $rses_take && $this->rses_font->rses_text_width( $rses_try, $size ) > $max_width ) {
					$rses_out[] = $rses_take;
					$rses_take  = $rses_ch;
					if ( $max_lines > 0 && count( $rses_out ) >= $max_lines ) {
						break 2;
					}
				} else {
					$rses_take = $rses_try;
				}
			}
			if ( '' !== $rses_take ) {
				$rses_out[] = $rses_take;
			}
		}
		if ( $max_lines > 0 && count( $rses_out ) > $max_lines ) {
			$rses_out = array_slice( $rses_out, 0, $max_lines );
			$rses_out[ $max_lines - 1 ] = $this->rses_font->rses_fit_text( $rses_out[ $max_lines - 1 ], $size, $max_width );
		}
		return empty( $rses_out ) ? array( '' ) : $rses_out;
	}

	private function rses_site_datetime(): string {
		$rses_format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		if ( '' === $rses_format ) {
			$rses_format = 'Y-m-d H:i:s';
		}
		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( $rses_format );
		}
		return (string) current_time( $rses_format );
	}

	private function rses_timezone_label(): string {
		if ( function_exists( 'wp_timezone_string' ) ) {
			$rses_tz = wp_timezone_string();
			if ( is_string( $rses_tz ) && '' !== $rses_tz ) {
				return $rses_tz;
			}
		}
		$rses_opt = get_option( 'timezone_string' );
		if ( is_string( $rses_opt ) && '' !== $rses_opt ) {
			return $rses_opt;
		}
		$rses_offset = (float) get_option( 'gmt_offset', 0 );
		$rses_sign   = $rses_offset >= 0 ? '+' : '-';
		return sprintf( 'UTC%s%s', $rses_sign, (string) abs( $rses_offset ) );
	}

	private function rses_status_label( string $status ): string {
		switch ( strtolower( $status ) ) {
			case 'verified':
			case 'decrypted-signed':
			case 'certified':
				return __( 'Verified', 'relatasoft-secure-election-suite' );
			case 'rejected':
				return __( 'Rejected', 'relatasoft-secure-election-suite' );
			default:
				return $status;
		}
	}

	private static function rses_upper( string $text ): string {
		if ( function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( $text, 'UTF-8' );
		}
		return strtoupper( $text );
	}

	private function rses_assemble(): string {
		$rses_pages = $this->rses_streams;
		if ( empty( $rses_pages ) ) {
			$rses_pages = array( '' );
		}

		$rses_widths    = $this->rses_font->rses_widths_array_for_lines( $this->rses_used_strings );
		$rses_tounicode = $this->rses_font->rses_tounicode_cmap( $this->rses_used_strings );
		$rses_font_bytes = $this->rses_font->rses_font_bytes();
		$rses_font_z     = gzcompress( $rses_font_bytes );
		$rses_filter     = false !== $rses_font_z ? '/Filter /FlateDecode ' : '';
		if ( false === $rses_font_z ) {
			$rses_font_z = $rses_font_bytes;
		}
		$rses_ps = $this->rses_font->rses_postscript_name();
		$rses_bb = $this->rses_font->rses_bbox();

		$rses_objects    = array();
		$rses_objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

		$rses_kids    = array();
		$rses_next    = 8;
		$page_ids     = array();
		$content_ids  = array();
		foreach ( array_keys( $rses_pages ) as $rses_idx ) {
			$page_ids[ $rses_idx ]    = $rses_next++;
			$content_ids[ $rses_idx ] = $rses_next++;
			$rses_kids[]              = $page_ids[ $rses_idx ] . ' 0 R';
		}

		$rses_objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $rses_kids ) . '] /Count ' . count( $rses_pages ) . ' >>';
		$rses_objects[3] = '<< /Type /Font /Subtype /Type0 /BaseFont /' . $rses_ps
			. ' /Encoding /Identity-H /DescendantFonts [4 0 R] /ToUnicode 7 0 R >>';
		$rses_objects[4] = '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $rses_ps
			. ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
			. ' /FontDescriptor 5 0 R /DW ' . (int) $this->rses_font->rses_missing_width()
			. ' /W ' . $rses_widths
			. ' /CIDToGIDMap /Identity >>';
		$rses_objects[5] = '<< /Type /FontDescriptor /FontName /' . $rses_ps
			. ' /Flags 32 /FontBBox [' . implode( ' ', $rses_bb ) . ']'
			. ' /ItalicAngle 0 /Ascent ' . (int) $this->rses_font->rses_ascent()
			. ' /Descent ' . (int) $this->rses_font->rses_descent()
			. ' /CapHeight ' . (int) $this->rses_font->rses_ascent()
			. ' /StemV 80 /FontFile2 6 0 R >>';
		$rses_objects[6] = '<< ' . $rses_filter . '/Length ' . strlen( $rses_font_z )
			. ' /Length1 ' . strlen( $rses_font_bytes ) . " >> stream\n" . $rses_font_z . "\nendstream";
		$rses_objects[7] = '<< /Length ' . strlen( $rses_tounicode ) . " >> stream\n" . $rses_tounicode . "\nendstream";

		foreach ( $rses_pages as $rses_idx => $rses_stream ) {
			$rses_pid = $page_ids[ $rses_idx ];
			$rses_cid = $content_ids[ $rses_idx ];
			$rses_objects[ $rses_pid ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
				. (int) self::RSES_PAGE_W . ' ' . (int) self::RSES_PAGE_H
				. '] /Contents ' . $rses_cid . ' 0 R /Resources << /Font << /F1 3 0 R >> >> >>';
			$rses_objects[ $rses_cid ] = '<< /Length ' . strlen( $rses_stream ) . " >> stream\n" . $rses_stream . "\nendstream";
		}

		ksort( $rses_objects, SORT_NUMERIC );
		$rses_pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$rses_offsets = array();
		$rses_max     = max( array_keys( $rses_objects ) );
		for ( $rses_id = 1; $rses_id <= $rses_max; ++$rses_id ) {
			if ( ! isset( $rses_objects[ $rses_id ] ) ) {
				continue;
			}
			$rses_offsets[ $rses_id ] = strlen( $rses_pdf );
			$rses_pdf                .= $rses_id . ' 0 obj ' . $rses_objects[ $rses_id ] . " endobj\n";
		}
		$rses_xref = strlen( $rses_pdf );
		$rses_size = $rses_max + 1;
		$rses_pdf .= "xref\n0 {$rses_size}\n0000000000 65535 f \n";
		for ( $rses_id = 1; $rses_id <= $rses_max; ++$rses_id ) {
			$rses_pdf .= isset( $rses_offsets[ $rses_id ] )
				? sprintf( "%010d 00000 n \n", $rses_offsets[ $rses_id ] )
				: "0000000000 65535 f \n";
		}
		$rses_pdf .= "trailer << /Size {$rses_size} /Root 1 0 R >>\nstartxref\n{$rses_xref}\n%%EOF";
		return $rses_pdf;
	}
}
