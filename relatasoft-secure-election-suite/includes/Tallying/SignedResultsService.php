<?php
/**
 * Digitally signed election results (Schnorr over election ElGamal key).
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Crypto\CryptoException;
use RelataSoft\SecureElectionSuite\Crypto\SchnorrSignature;
use RelataSoft\SecureElectionSuite\Exports\CertificationPdf;
use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\Exports\JsonExport;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Tallies\WordPressSignedResultsStore;
use RelataSoft\SecureElectionSuite\Painel\Application\Persistence\PersistenceGateway;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\ConfirmWord;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Build, store, export, and verify signed result packages.
 */
class SignedResultsService {

	/** Legacy single-signature package (results + PDF hashes in one message). */
	public const RSES_PACKAGE_V1 = 'election-results-v1';

	/** Current package: results signature embedded in PDF; separate PDF-binding signature. */
	public const RSES_PACKAGE = 'election-results-v2';

	public const RSES_PDF_BIND = 'election-results-pdf-v2';

	/**
	 * Register download hooks (no public verify shortcode).
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_download_signed_results', array( self::class, 'rses_handle_download_json' ) );
		add_action( 'admin_post_rses_download_signed_pdf', array( self::class, 'rses_handle_download_pdf' ) );
		add_action( 'admin_post_rses_verify_signed_results', array( self::class, 'rses_handle_verify_auditor' ) );
		add_action( 'admin_post_rses_delete_signed_results', array( self::class, 'rses_handle_delete_persisted' ) );
	}

	/**
	 * Option key for persisted signed package metadata (Adapter #1 compatibility).
	 *
	 * @param int $import_id Import ID.
	 */
	public static function rses_persist_option_key( int $import_id ): string {
		return WordPressSignedResultsStore::optionKey( $import_id );
	}

	/**
	 * Transient key for signed package.
	 *
	 * @param int $import_id Import ID.
	 */
	public static function rses_package_transient_key( int $import_id ): string {
		return 'rses_signed_results_' . $import_id;
	}

	/**
	 * Transient key for signed PDF bytes.
	 *
	 * @param int $import_id Import ID.
	 */
	public static function rses_pdf_transient_key( int $import_id ): string {
		return 'rses_signed_pdf_' . $import_id;
	}

	/**
	 * Build and sign results + PDF using reconstructed private key.
	 *
	 * @param int                 $import_id Import ID.
	 * @param array<string,mixed> $manifest  Import manifest.
	 * @param array<string,mixed> $decrypted Decryption result data.
	 * @param \GMP                $p         Prime p.
	 * @param \GMP                $q         Subgroup order.
	 * @param \GMP                $g         Generator.
	 * @param \GMP                $x         Private exponent.
	 * @param \GMP                $y         Public y.
	 * @return array{package:array<string,mixed>,pdf:string}
	 * @throws CryptoException On sign failure.
	 */
	public static function rses_sign_decryption(
		int $import_id,
		array $manifest,
		array $decrypted,
		\GMP $p,
		\GMP $q,
		\GMP $g,
		\GMP $x,
		\GMP $y
	): array {
		$rses_import = TallyImportRepository::rses_get( $import_id );
		$rses_ballot = is_array( $manifest['ballot'] ?? null ) ? $manifest['ballot'] : array();
		$rses_raw    = is_array( $decrypted['decrypted_results'] ?? null ) ? $decrypted['decrypted_results'] : array();

		$rses_humanized = DecryptedResultsPresenter::rses_humanize(
			$rses_raw,
			$rses_ballot,
			DecryptedResultsPresenter::RSES_SORT_COUNT_DESC
		);

		$rses_election = $rses_import
			? TallyImportRepository::rses_display_election_title( $rses_import )
			: (string) ( $manifest['election']['title'] ?? '' );
		$rses_round = $rses_import
			? TallyImportRepository::rses_display_round_title( $rses_import )
			: (string) ( $manifest['round']['title'] ?? '' );

		$rses_public = array(
			'p' => BigInt::toDecimalString( $p ),
			'q' => BigInt::toDecimalString( $q ),
			'g' => BigInt::toDecimalString( $g ),
			'y' => BigInt::toDecimalString( $y ),
		);
		if ( ! empty( $manifest['public_key']['key_label'] ) ) {
			$rses_public['key_label'] = (string) $manifest['public_key']['key_label'];
		}
		if ( ! empty( $manifest['manifest']['key_label'] ) && empty( $rses_public['key_label'] ) ) {
			$rses_public['key_label'] = (string) $manifest['manifest']['key_label'];
		}

		$rses_results = array(
			'decrypted_results' => $rses_raw,
			'humanized_results' => $rses_humanized,
			'ballot_count'      => (int) ( $manifest['manifest']['ballot_count'] ?? ( $rses_import->ballot_count ?? 0 ) ),
			'threshold'         => (int) ( $decrypted['threshold'] ?? 0 ),
			'submissions'       => (int) ( $decrypted['submissions'] ?? 0 ),
		);

		$rses_fingerprint = TallyImportRepository::rses_public_key_fingerprint( $rses_public );
		$rses_report_meta = array(
			'election_title'           => $rses_election,
			'round_title'              => $rses_round,
			'round_number'             => $manifest['round']['round_number'] ?? null,
			'verification_status'      => 'decrypted-signed',
			'certified_at'             => '',
			'public_key_hash'          => HashService::rses_hash_json( $rses_public ),
			'public_key_fingerprint'   => $rses_fingerprint,
			'signature_scheme'         => SchnorrSignature::RSES_SCHEME,
			'decrypted_result_hash'    => HashService::rses_hash_json( $rses_raw ),
			'ballot_count'             => $rses_results['ballot_count'],
			'threshold'                => $rses_results['threshold'],
			'decrypted_results'        => $rses_raw,
		);

		$rses_results_sha = HashService::rses_hash_json( $rses_results );

		// 1) Sign results first (no PDF hash — avoids circular embed).
		$rses_results_message = self::rses_results_signature_message( $rses_results_sha, $import_id, $rses_election );
		$rses_results_sig     = SchnorrSignature::sign( $rses_results_message, $p, $q, $g, $x, $y );

		$rses_package_embed = array(
			'rses_signed_package'    => self::RSES_PACKAGE,
			'scheme'                 => SchnorrSignature::RSES_SCHEME,
			'signed_at'              => gmdate( 'c' ),
			'plugin_version'         => RSES_VERSION,
			'import_id'              => $import_id,
			'election_title'         => $rses_election,
			'round_title'            => $rses_round,
			'source_site'            => $manifest['manifest']['source_site'] ?? ( $rses_import->source_site_url ?? null ),
			'public_key'             => $rses_public,
			'public_key_fingerprint' => $rses_fingerprint,
			'results'                => $rses_results,
			'documents'              => array(
				'results_sha256' => $rses_results_sha,
			),
			'signature_message'      => $rses_results_message,
			'signature'              => $rses_results_sig,
			'verify_note'            => __(
				'Results are Schnorr-signed under the election public key. The downloadable signed-results.json also includes documents.pdf_sha256 and pdf_signature binding the full PDF bytes.',
				'relatasoft-secure-election-suite'
			),
		);

		// 2) Styled humanized PDF + embedded results-signed JSON (site language / UTF-8).
		$rses_pdf     = CertificationPdf::rses_generate( $rses_report_meta, $rses_humanized, $rses_package_embed );
		$rses_pdf_sha = hash( 'sha256', $rses_pdf );

		// 3) Bind the whole PDF with a second Schnorr signature.
		$rses_documents = array(
			'results_sha256' => $rses_results_sha,
			'pdf_sha256'     => $rses_pdf_sha,
		);
		$rses_pdf_message = self::rses_pdf_signature_message( $rses_documents, $import_id, $rses_election );
		$rses_pdf_sig     = SchnorrSignature::sign( $rses_pdf_message, $p, $q, $g, $x, $y );

		$rses_package = $rses_package_embed;
		$rses_package['documents']              = $rses_documents;
		$rses_package['pdf_signature_message']  = $rses_pdf_message;
		$rses_package['pdf_signature']          = $rses_pdf_sig;
		$rses_package['verify_note']            = __(
			'Anyone with this file can verify authenticity using only the embedded public_key: (1) check signature over results_sha256; (2) check pdf_signature over results_sha256 + pdf_sha256 against the PDF bytes. The PDF itself embeds the results-signed JSON after the humanized tally.',
			'relatasoft-secure-election-suite'
		);

		set_transient( self::rses_package_transient_key( $import_id ), $rses_package, WEEK_IN_SECONDS );
		set_transient( self::rses_pdf_transient_key( $import_id ), base64_encode( $rses_pdf ), WEEK_IN_SECONDS );

		self::rses_persist_package( $import_id, $rses_package, $rses_pdf );

		return array(
			'package' => $rses_package,
			'pdf'     => $rses_pdf,
		);
	}

	/**
	 * Canonical message for results-only Schnorr (v2, embedded in PDF).
	 *
	 * @param string $results_sha Results document hash.
	 * @param int    $import_id   Import ID.
	 * @param string $election    Election title.
	 */
	public static function rses_results_signature_message( string $results_sha, int $import_id, string $election ): string {
		return implode(
			"\n",
			array(
				self::RSES_PACKAGE,
				SchnorrSignature::RSES_SCHEME,
				(string) $import_id,
				$election,
				$results_sha,
			)
		);
	}

	/**
	 * Canonical message binding PDF bytes (v2).
	 *
	 * @param array{results_sha256:string,pdf_sha256:string} $documents Document hashes.
	 * @param int                                            $import_id Import ID.
	 * @param string                                         $election  Election title.
	 */
	public static function rses_pdf_signature_message( array $documents, int $import_id, string $election ): string {
		return implode(
			"\n",
			array(
				self::RSES_PDF_BIND,
				SchnorrSignature::RSES_SCHEME,
				(string) $import_id,
				$election,
				(string) ( $documents['results_sha256'] ?? '' ),
				(string) ( $documents['pdf_sha256'] ?? '' ),
			)
		);
	}

	/**
	 * Legacy v1 message (results + PDF hashes in one signature).
	 *
	 * @param array{results_sha256?:string,pdf_sha256?:string} $documents Document hashes.
	 * @param int                                              $import_id Import ID.
	 * @param string                                           $election  Election title.
	 */
	public static function rses_signature_message( array $documents, int $import_id, string $election ): string {
		return implode(
			"\n",
			array(
				self::RSES_PACKAGE_V1,
				SchnorrSignature::RSES_SCHEME,
				(string) $import_id,
				$election,
				(string) ( $documents['results_sha256'] ?? '' ),
				(string) ( $documents['pdf_sha256'] ?? '' ),
			)
		);
	}

	/**
	 * Verify a signed package (optionally with an external PDF binary).
	 *
	 * @param array<string,mixed> $package Signed package.
	 * @param string|null         $pdf     Optional PDF bytes to check against documents.pdf_sha256.
	 * @return array{valid:bool,errors:array<int,string>,details:array<string,mixed>}
	 */
	public static function rses_verify_package( array $package, ?string $pdf = null ): array {
		$rses_errors  = array();
		$rses_details = array();
		$rses_kind    = (string) ( $package['rses_signed_package'] ?? '' );

		if ( self::RSES_PACKAGE !== $rses_kind && self::RSES_PACKAGE_V1 !== $rses_kind ) {
			$rses_errors[] = __( 'Not an election-results signed package (expected v1 or v2).', 'relatasoft-secure-election-suite' );
		}

		$rses_public = is_array( $package['public_key'] ?? null ) ? $package['public_key'] : array();
		foreach ( array( 'p', 'q', 'g', 'y' ) as $rses_f ) {
			if ( empty( $rses_public[ $rses_f ] ) ) {
				$rses_errors[] = sprintf(
					/* translators: %s: field name */
					__( 'Missing public key field: %s', 'relatasoft-secure-election-suite' ),
					$rses_f
				);
			}
		}

		$rses_results = is_array( $package['results'] ?? null ) ? $package['results'] : array();
		$rses_docs    = is_array( $package['documents'] ?? null ) ? $package['documents'] : array();
		$rses_sig     = is_array( $package['signature'] ?? null ) ? $package['signature'] : array();

		if ( empty( $rses_errors ) ) {
			$rses_results_sha = HashService::rses_hash_json( $rses_results );
			$rses_details['results_sha256_computed'] = $rses_results_sha;
			$rses_details['results_sha256_claimed']  = (string) ( $rses_docs['results_sha256'] ?? '' );
			if ( ! hash_equals( (string) ( $rses_docs['results_sha256'] ?? '' ), $rses_results_sha ) ) {
				$rses_errors[] = __( 'Results content does not match documents.results_sha256 (package may have been altered).', 'relatasoft-secure-election-suite' );
			}

			$rses_import_id = (int) ( $package['import_id'] ?? 0 );
			$rses_election  = (string) ( $package['election_title'] ?? '' );

			if ( is_string( $pdf ) ) {
				$rses_pdf_sha = hash( 'sha256', $pdf );
				$rses_details['pdf_sha256_computed'] = $rses_pdf_sha;
				$rses_details['pdf_sha256_claimed']  = (string) ( $rses_docs['pdf_sha256'] ?? '' );
				if ( '' !== (string) ( $rses_docs['pdf_sha256'] ?? '' )
					&& ! hash_equals( (string) $rses_docs['pdf_sha256'], $rses_pdf_sha ) ) {
					$rses_errors[] = __( 'PDF bytes do not match documents.pdf_sha256.', 'relatasoft-secure-election-suite' );
				}
			}

			try {
				$rses_p = BigInt::fromDecimalString( (string) $rses_public['p'] );
				$rses_q = BigInt::fromDecimalString( (string) $rses_public['q'] );
				$rses_g = BigInt::fromDecimalString( (string) $rses_public['g'] );
				$rses_y = BigInt::fromDecimalString( (string) $rses_public['y'] );

				if ( self::RSES_PACKAGE === $rses_kind ) {
					$rses_message = self::rses_results_signature_message(
						(string) ( $rses_docs['results_sha256'] ?? '' ),
						$rses_import_id,
						$rses_election
					);
					$rses_details['signature_message'] = $rses_message;
					$rses_ok = SchnorrSignature::verify( $rses_message, $rses_sig, $rses_p, $rses_q, $rses_g, $rses_y );
					$rses_details['signature_valid'] = $rses_ok;
					if ( ! $rses_ok ) {
						$rses_errors[] = __( 'Schnorr signature verification failed under the embedded public key.', 'relatasoft-secure-election-suite' );
					}

					$rses_pdf_sig = is_array( $package['pdf_signature'] ?? null ) ? $package['pdf_signature'] : array();
					if ( empty( $rses_pdf_sig ) || empty( $rses_docs['pdf_sha256'] ) ) {
						$rses_errors[] = __( 'Missing pdf_signature / documents.pdf_sha256 (incomplete v2 package).', 'relatasoft-secure-election-suite' );
					} else {
						$rses_pdf_message = self::rses_pdf_signature_message(
							array(
								'results_sha256' => (string) ( $rses_docs['results_sha256'] ?? '' ),
								'pdf_sha256'     => (string) ( $rses_docs['pdf_sha256'] ?? '' ),
							),
							$rses_import_id,
							$rses_election
						);
						$rses_details['pdf_signature_message'] = $rses_pdf_message;
						$rses_pdf_ok = SchnorrSignature::verify( $rses_pdf_message, $rses_pdf_sig, $rses_p, $rses_q, $rses_g, $rses_y );
						$rses_details['pdf_signature_valid'] = $rses_pdf_ok;
						if ( ! $rses_pdf_ok ) {
							$rses_errors[] = __( 'PDF Schnorr signature verification failed under the embedded public key.', 'relatasoft-secure-election-suite' );
						}
					}
				} else {
					// Legacy v1: one signature over results_sha256 + pdf_sha256.
					$rses_message = self::rses_signature_message(
						array(
							'results_sha256' => (string) ( $rses_docs['results_sha256'] ?? '' ),
							'pdf_sha256'     => (string) ( $rses_docs['pdf_sha256'] ?? '' ),
						),
						$rses_import_id,
						$rses_election
					);
					$rses_details['signature_message'] = $rses_message;
					$rses_ok = SchnorrSignature::verify( $rses_message, $rses_sig, $rses_p, $rses_q, $rses_g, $rses_y );
					$rses_details['signature_valid'] = $rses_ok;
					if ( ! $rses_ok ) {
						$rses_errors[] = __( 'Schnorr signature verification failed under the embedded public key.', 'relatasoft-secure-election-suite' );
					}
				}
			} catch ( CryptoException $rses_e ) {
				$rses_errors[] = $rses_e->getMessage();
			}
		}

		return array(
			'valid'   => empty( $rses_errors ),
			'errors'  => $rses_errors,
			'details' => $rses_details,
		);
	}

	/**
	 * Whether a signed package is already persisted for this import.
	 *
	 * @param int $import_id Import ID.
	 */
	public static function rses_has_persisted( int $import_id ): bool {
		if ( $import_id < 1 ) {
			return false;
		}
		$rses_meta = PersistenceGateway::get()->signedResults->get( $import_id );
		if ( is_array( $rses_meta ) && ! empty( $rses_meta['package'] ) && is_array( $rses_meta['package'] ) ) {
			return true;
		}
		return is_array( self::rses_get_package( $import_id ) );
	}

	/**
	 * Persist signed package JSON and PDF (private media + option).
	 *
	 * @param int                 $import_id Import ID.
	 * @param array<string,mixed> $package   Signed package.
	 * @param string              $pdf       PDF bytes.
	 * @return array{pdf_attachment_id:int,zip_attachment_id:int}
	 */
	public static function rses_persist_package( int $import_id, array $package, string $pdf ): array {
		$rses_pdf_id = self::rses_store_private_attachment(
			$pdf,
			'signed-results-import-' . $import_id . '.pdf',
			'application/pdf',
			$import_id
		);

		$rses_json     = wp_json_encode( $package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$rses_json_id  = 0;
		if ( is_string( $rses_json ) && '' !== $rses_json ) {
			$rses_json_id = self::rses_store_private_attachment(
				$rses_json,
				'signed-results-import-' . $import_id . '.json',
				'application/json',
				$import_id
			);
		}

		PersistenceGateway::get()->signedResults->put(
			$import_id,
			array(
				'package'           => $package,
				'pdf_attachment_id' => $rses_pdf_id,
				'json_attachment_id'=> $rses_json_id,
				'persisted_at'      => gmdate( 'c' ),
			)
		);

		return array(
			'pdf_attachment_id' => $rses_pdf_id,
			'zip_attachment_id' => 0,
			'json_attachment_id'=> $rses_json_id,
		);
	}

	/**
	 * Store bytes as a private (unlisted) media attachment.
	 *
	 * @param string $bytes     File contents.
	 * @param string $filename  Suggested filename.
	 * @param string $mime      MIME type.
	 * @param int    $import_id Import ID for meta.
	 * @return int Attachment ID or 0.
	 */
	private static function rses_store_private_attachment( string $bytes, string $filename, string $mime, int $import_id ): int {
		if ( '' === $bytes || ! function_exists( 'wp_upload_bits' ) ) {
			return 0;
		}

		$rses_upload = wp_upload_bits( sanitize_file_name( $filename ), null, $bytes );
		if ( ! empty( $rses_upload['error'] ) || empty( $rses_upload['file'] ) ) {
			return 0;
		}

		$rses_filetype = wp_check_filetype( $rses_upload['file'], null );
		$rses_id       = wp_insert_attachment(
			array(
				'post_mime_type' => $rses_filetype['type'] ?: $mime,
				'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'private',
			),
			$rses_upload['file']
		);

		if ( is_wp_error( $rses_id ) || ! $rses_id ) {
			return 0;
		}

		$rses_id = (int) $rses_id;
		update_post_meta( $rses_id, '_rses_signed_import_id', $import_id );
		update_post_meta( $rses_id, '_rses_private_election_artifact', '1' );

		return $rses_id;
	}

	/**
	 * Attachment IDs for a persisted package (for certification rows).
	 *
	 * @param int $import_id Import ID.
	 * @return array{pdf_attachment_id:int,json_attachment_id:int}
	 */
	public static function rses_get_attachment_ids( int $import_id ): array {
		$rses_meta = PersistenceGateway::get()->signedResults->get( $import_id );
		if ( ! is_array( $rses_meta ) ) {
			return array(
				'pdf_attachment_id'  => 0,
				'json_attachment_id' => 0,
			);
		}
		return array(
			'pdf_attachment_id'  => (int) ( $rses_meta['pdf_attachment_id'] ?? 0 ),
			'json_attachment_id' => (int) ( $rses_meta['json_attachment_id'] ?? 0 ),
		);
	}

	/**
	 * Load stored signed package (persistence first, then transient).
	 *
	 * @param int $import_id Import ID.
	 * @return array<string,mixed>|null
	 */
	public static function rses_get_package( int $import_id ): ?array {
		$rses_meta = PersistenceGateway::get()->signedResults->get( $import_id );
		if ( is_array( $rses_meta ) && ! empty( $rses_meta['package'] ) && is_array( $rses_meta['package'] ) ) {
			return $rses_meta['package'];
		}

		$rses_atts = is_array( $rses_meta ) ? $rses_meta : array();
		$rses_json_id = (int) ( $rses_atts['json_attachment_id'] ?? 0 );
		if ( $rses_json_id > 0 ) {
			$rses_path = get_attached_file( $rses_json_id );
			if ( is_string( $rses_path ) && is_readable( $rses_path ) ) {
				$rses_raw = file_get_contents( $rses_path );
				$rses_decoded = is_string( $rses_raw ) ? json_decode( $rses_raw, true ) : null;
				if ( is_array( $rses_decoded ) ) {
					return $rses_decoded;
				}
			}
		}

		$rses_pkg = get_transient( self::rses_package_transient_key( $import_id ) );
		return is_array( $rses_pkg ) ? $rses_pkg : null;
	}

	/**
	 * Load stored signed PDF bytes (persistence first, then transient).
	 *
	 * @param int $import_id Import ID.
	 * @return string|null
	 */
	public static function rses_get_pdf( int $import_id ): ?string {
		$rses_meta = PersistenceGateway::get()->signedResults->get( $import_id );
		$rses_pdf_id = is_array( $rses_meta ) ? (int) ( $rses_meta['pdf_attachment_id'] ?? 0 ) : 0;
		if ( $rses_pdf_id > 0 ) {
			$rses_path = get_attached_file( $rses_pdf_id );
			if ( is_string( $rses_path ) && is_readable( $rses_path ) ) {
				$rses_pdf = file_get_contents( $rses_path );
				if ( is_string( $rses_pdf ) && '' !== $rses_pdf ) {
					return $rses_pdf;
				}
			}
		}

		$rses_b64 = get_transient( self::rses_pdf_transient_key( $import_id ) );
		if ( ! is_string( $rses_b64 ) || '' === $rses_b64 ) {
			return null;
		}
		$rses_pdf = base64_decode( $rses_b64, true );
		return is_string( $rses_pdf ) ? $rses_pdf : null;
	}

	/**
	 * Drop signed artifacts for an import (internal; no ConfirmWord).
	 *
	 * @param int $import_id Import ID.
	 */
	public static function rses_clear( int $import_id ): void {
		delete_transient( self::rses_package_transient_key( $import_id ) );
		delete_transient( self::rses_pdf_transient_key( $import_id ) );

		$rses_meta = PersistenceGateway::get()->signedResults->get( $import_id );
		if ( is_array( $rses_meta ) ) {
			foreach ( array( 'pdf_attachment_id', 'json_attachment_id' ) as $rses_key ) {
				$rses_aid = (int) ( $rses_meta[ $rses_key ] ?? 0 );
				if ( $rses_aid > 0 && function_exists( 'wp_delete_attachment' ) ) {
					wp_delete_attachment( $rses_aid, true );
				}
			}
		}
		PersistenceGateway::get()->signedResults->delete( $import_id );
	}

	/**
	 * Delete persisted signed package after typed ConfirmWord.
	 *
	 * @param int    $import_id Import ID.
	 * @param string $typed     Typed confirmation word.
	 * @return array{ok:bool,message:string}
	 */
	public static function rses_delete_persisted( int $import_id, string $typed ): array {
		if ( ! ConfirmWord::rses_matches( $typed ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: required confirmation word in the active locale */
					__( 'Deletion cancelled. Type “%s” exactly to confirm permanently deleting the signed package.', 'relatasoft-secure-election-suite' ),
					ConfirmWord::rses_word()
				),
			);
		}

		if ( $import_id < 1 || ! self::rses_has_persisted( $import_id ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'No persisted signed package found for this import.', 'relatasoft-secure-election-suite' ),
			);
		}

		self::rses_clear( $import_id );
		delete_transient( 'rses_decryption_result_' . $import_id );

		return array(
			'ok'      => true,
			'message' => __( 'Signed package deleted. You may decrypt and re-sign this import again.', 'relatasoft-secure-election-suite' ),
		);
	}

	/**
	 * Download signed JSON.
	 */
	public static function rses_handle_download_json(): void {
		Capability::rses_require_tally_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_CERTIFICATION );

		$rses_import_id = absint( $_GET['import_id'] ?? 0 );
		$rses_pkg       = self::rses_get_package( $rses_import_id );
		if ( ! $rses_pkg ) {
			wp_die( esc_html__( 'Signed results not found. Decrypt the tally again after the threshold is met.', 'relatasoft-secure-election-suite' ) );
		}

		JsonExport::rses_send_download( 'signed-results-import-' . $rses_import_id . '.json', $rses_pkg );
	}

	/**
	 * Download signed PDF (hash bound in signed JSON).
	 */
	public static function rses_handle_download_pdf(): void {
		Capability::rses_require_tally_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_CERTIFICATION );

		$rses_import_id = absint( $_GET['import_id'] ?? 0 );
		$rses_pdf       = self::rses_get_pdf( $rses_import_id );
		if ( ! is_string( $rses_pdf ) ) {
			wp_die( esc_html__( 'Signed PDF not found. Decrypt the tally again after the threshold is met.', 'relatasoft-secure-election-suite' ) );
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'signed-results-import-' . $rses_import_id . '.pdf' ) . '"' );
		header( 'Content-Length: ' . strlen( $rses_pdf ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		echo $rses_pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Auditor verify handler (upload/paste JSON + optional PDF).
	 */
	public static function rses_handle_verify_auditor(): void {
		Capability::rses_require_audit_view();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_CERTIFICATION );

		$rses_json = '';
		if ( ! empty( $_FILES['rses_signed_json_file']['tmp_name'] ) && is_uploaded_file( (string) $_FILES['rses_signed_json_file']['tmp_name'] ) ) {
			$rses_json = (string) file_get_contents( (string) $_FILES['rses_signed_json_file']['tmp_name'] );
		} elseif ( isset( $_POST['rses_signed_json'] ) ) {
			$rses_json = (string) wp_unslash( $_POST['rses_signed_json'] );
		}

		$rses_data = json_decode( $rses_json, true );
		if ( ! is_array( $rses_data ) ) {
			set_transient(
				'rses_audit_verify_flash_' . get_current_user_id(),
				array(
					'valid'   => false,
					'errors'  => array( __( 'Invalid JSON.', 'relatasoft-secure-election-suite' ) ),
					'details' => array(),
				),
				5 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( admin_url( 'admin.php?page=rses-audit-certification&rses_verify=0' ) );
			exit;
		}

		$rses_pdf = null;
		if ( ! empty( $_FILES['rses_signed_pdf_file']['tmp_name'] ) && is_uploaded_file( (string) $_FILES['rses_signed_pdf_file']['tmp_name'] ) ) {
			$rses_pdf = (string) file_get_contents( (string) $_FILES['rses_signed_pdf_file']['tmp_name'] );
		}

		$rses_result = self::rses_verify_package( $rses_data, $rses_pdf );
		set_transient(
			'rses_audit_verify_flash_' . get_current_user_id(),
			$rses_result,
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			admin_url(
				'admin.php?page=rses-audit-certification&rses_verify=' . ( $rses_result['valid'] ? '1' : '0' )
			)
		);
		exit;
	}

	/**
	 * Admin POST: delete persisted signed artifacts with ConfirmWord.
	 */
	public static function rses_handle_delete_persisted(): void {
		Capability::rses_require_tally_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_CERTIFICATION );

		$rses_import_id = absint( $_POST['tally_import_id'] ?? 0 );
		$rses_typed     = isset( $_POST['rses_delete_confirm'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['rses_delete_confirm'] ) )
			: '';

		$rses_result = self::rses_delete_persisted( $rses_import_id, $rses_typed );
		set_transient(
			'rses_signed_delete_flash_' . get_current_user_id(),
			$rses_result,
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			admin_url(
				'admin.php?page=rses-certification&rses_signed_deleted=' . ( $rses_result['ok'] ? '1' : '0' )
			)
		);
		exit;
	}
}
