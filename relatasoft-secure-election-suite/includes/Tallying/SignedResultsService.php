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
use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\Exports\JsonExport;
use RelataSoft\SecureElectionSuite\Exports\PdfReport;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Build, store, export, and verify signed result packages.
 */
class SignedResultsService {

	public const RSES_PACKAGE = 'election-results-v1';

	/**
	 * Register download / verify hooks.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_download_signed_results', array( self::class, 'rses_handle_download_json' ) );
		add_action( 'admin_post_rses_download_signed_pdf', array( self::class, 'rses_handle_download_pdf' ) );
		add_action( 'admin_post_rses_verify_signed_results', array( self::class, 'rses_handle_verify_admin' ) );
		add_shortcode( 'rses_verify_signed_results', array( self::class, 'rses_render_verify_shortcode' ) );
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

		$rses_report_meta = array(
			'election_title'        => $rses_election,
			'round_title'           => $rses_round,
			'round_number'          => $manifest['round']['round_number'] ?? null,
			'verification_status'   => 'decrypted-signed',
			'certified_at'          => '',
			'public_key_hash'       => HashService::rses_hash_json( $rses_public ),
			'decrypted_result_hash' => HashService::rses_hash_json( $rses_raw ),
			'ballot_count'          => $rses_results['ballot_count'],
			'threshold'             => $rses_results['threshold'],
			'decrypted_results'     => $rses_raw,
		);

		$rses_results_sha = HashService::rses_hash_json( $rses_results );

		$rses_pdf_lines = DecryptedResultsPresenter::rses_pdf_lines( $rses_report_meta, $rses_humanized );
		$rses_pdf_lines[] = '';
		$rses_pdf_lines[] = __( 'Digital signature', 'relatasoft-secure-election-suite' );
		$rses_pdf_lines[] = str_repeat( '-', 40 );
		$rses_pdf_lines[] = __( 'Signed with the election private key (Schnorr). Verify using signed-results.json and this file’s SHA-256.', 'relatasoft-secure-election-suite' );
		$rses_pdf_lines[] = __( 'Public-key fingerprint:', 'relatasoft-secure-election-suite' ) . ' ' . TallyImportRepository::rses_public_key_fingerprint( $rses_public );
		$rses_pdf_lines[] = __( 'Scheme:', 'relatasoft-secure-election-suite' ) . ' ' . SchnorrSignature::RSES_SCHEME;
		$rses_pdf_lines[] = __( 'Results SHA-256:', 'relatasoft-secure-election-suite' ) . ' ' . $rses_results_sha;

		$rses_pdf     = PdfReport::rses_generate( $rses_pdf_lines );
		$rses_pdf_sha = hash( 'sha256', $rses_pdf );

		$rses_documents = array(
			'results_sha256' => $rses_results_sha,
			'pdf_sha256'     => $rses_pdf_sha,
		);
		$rses_message   = self::rses_signature_message( $rses_documents, $import_id, $rses_election );
		$rses_signature = SchnorrSignature::sign( $rses_message, $p, $q, $g, $x, $y );

		$rses_package = array(
			'rses_signed_package'    => self::RSES_PACKAGE,
			'scheme'                 => SchnorrSignature::RSES_SCHEME,
			'signed_at'              => gmdate( 'c' ),
			'plugin_version'         => RSES_VERSION,
			'import_id'              => $import_id,
			'election_title'         => $rses_election,
			'round_title'            => $rses_round,
			'source_site'            => $manifest['manifest']['source_site'] ?? ( $rses_import->source_site_url ?? null ),
			'public_key'             => $rses_public,
			'public_key_fingerprint' => TallyImportRepository::rses_public_key_fingerprint( $rses_public ),
			'results'                => $rses_results,
			'documents'              => $rses_documents,
			'signature_message'      => $rses_message,
			'signature'              => $rses_signature,
			'verify_note'            => __(
				'Anyone with this file can verify authenticity using only the embedded public_key: recompute documents hashes from results / PDF, rebuild signature_message, and check the Schnorr signature.',
				'relatasoft-secure-election-suite'
			),
		);

		set_transient( self::rses_package_transient_key( $import_id ), $rses_package, WEEK_IN_SECONDS );
		set_transient( self::rses_pdf_transient_key( $import_id ), base64_encode( $rses_pdf ), WEEK_IN_SECONDS );

		return array(
			'package' => $rses_package,
			'pdf'     => $rses_pdf,
		);
	}

	/**
	 * Canonical message signed by Schnorr.
	 *
	 * @param array{results_sha256:string,pdf_sha256:string} $documents Document hashes.
	 * @param int                                            $import_id Import ID.
	 * @param string                                         $election  Election title.
	 */
	public static function rses_signature_message( array $documents, int $import_id, string $election ): string {
		return implode(
			"\n",
			array(
				self::RSES_PACKAGE,
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

		if ( ( $package['rses_signed_package'] ?? '' ) !== self::RSES_PACKAGE ) {
			$rses_errors[] = __( 'Not an election-results-v1 signed package.', 'relatasoft-secure-election-suite' );
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

			$rses_message = self::rses_signature_message(
				array(
					'results_sha256' => (string) ( $rses_docs['results_sha256'] ?? '' ),
					'pdf_sha256'     => (string) ( $rses_docs['pdf_sha256'] ?? '' ),
				),
				(int) ( $package['import_id'] ?? 0 ),
				(string) ( $package['election_title'] ?? '' )
			);
			$rses_details['signature_message'] = $rses_message;

			if ( is_string( $pdf ) ) {
				$rses_pdf_sha = hash( 'sha256', $pdf );
				$rses_details['pdf_sha256_computed'] = $rses_pdf_sha;
				$rses_details['pdf_sha256_claimed']  = (string) ( $rses_docs['pdf_sha256'] ?? '' );
				if ( ! hash_equals( (string) ( $rses_docs['pdf_sha256'] ?? '' ), $rses_pdf_sha ) ) {
					$rses_errors[] = __( 'PDF bytes do not match documents.pdf_sha256.', 'relatasoft-secure-election-suite' );
				}
			}

			try {
				$rses_p = BigInt::fromDecimalString( (string) $rses_public['p'] );
				$rses_q = BigInt::fromDecimalString( (string) $rses_public['q'] );
				$rses_g = BigInt::fromDecimalString( (string) $rses_public['g'] );
				$rses_y = BigInt::fromDecimalString( (string) $rses_public['y'] );
				$rses_ok = SchnorrSignature::verify( $rses_message, $rses_sig, $rses_p, $rses_q, $rses_g, $rses_y );
				$rses_details['signature_valid'] = $rses_ok;
				if ( ! $rses_ok ) {
					$rses_errors[] = __( 'Schnorr signature verification failed under the embedded public key.', 'relatasoft-secure-election-suite' );
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
	 * Load stored signed package.
	 *
	 * @param int $import_id Import ID.
	 * @return array<string,mixed>|null
	 */
	public static function rses_get_package( int $import_id ): ?array {
		$rses_pkg = get_transient( self::rses_package_transient_key( $import_id ) );
		return is_array( $rses_pkg ) ? $rses_pkg : null;
	}

	/**
	 * Load stored signed PDF bytes.
	 *
	 * @param int $import_id Import ID.
	 * @return string|null
	 */
	public static function rses_get_pdf( int $import_id ): ?string {
		$rses_b64 = get_transient( self::rses_pdf_transient_key( $import_id ) );
		if ( ! is_string( $rses_b64 ) || '' === $rses_b64 ) {
			return null;
		}
		$rses_pdf = base64_decode( $rses_b64, true );
		return is_string( $rses_pdf ) ? $rses_pdf : null;
	}

	/**
	 * Drop signed artifacts for an import.
	 *
	 * @param int $import_id Import ID.
	 */
	public static function rses_clear( int $import_id ): void {
		delete_transient( self::rses_package_transient_key( $import_id ) );
		delete_transient( self::rses_pdf_transient_key( $import_id ) );
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
	 * Admin verify form redirect.
	 */
	public static function rses_handle_verify_admin(): void {
		Capability::rses_require_tally_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_CERTIFICATION );

		$rses_json = isset( $_POST['rses_signed_json'] ) ? wp_unslash( $_POST['rses_signed_json'] ) : '';
		$rses_data = json_decode( (string) $rses_json, true );
		if ( ! is_array( $rses_data ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=rses-certification&rses_verify=0' ) );
			exit;
		}

		$rses_result = self::rses_verify_package( $rses_data );
		set_transient(
			'rses_verify_flash_' . get_current_user_id(),
			$rses_result,
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			admin_url(
				'admin.php?page=rses-certification&rses_verify=' . ( $rses_result['valid'] ? '1' : '0' )
			)
		);
		exit;
	}

	/**
	 * Public shortcode: paste signed-results.json and verify with embedded public key.
	 *
	 * @param array<string,string> $atts Attributes.
	 * @return string
	 */
	public static function rses_render_verify_shortcode( $atts = array() ): string {
		$rses_out    = '';
		$rses_result = null;

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['rses_verify_signed_public'] ) ) {
			$rses_nonce = isset( $_POST['_rses_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rses_nonce'] ) ) : '';
			if ( wp_verify_nonce( $rses_nonce, 'rses_verify_signed_public' ) ) {
				$rses_json = isset( $_POST['rses_signed_json'] ) ? wp_unslash( $_POST['rses_signed_json'] ) : '';
				$rses_data = json_decode( (string) $rses_json, true );
				$rses_result = is_array( $rses_data )
					? self::rses_verify_package( $rses_data )
					: array(
						'valid'  => false,
						'errors' => array( __( 'Invalid JSON.', 'relatasoft-secure-election-suite' ) ),
						'details'=> array(),
					);
			}
		}

		ob_start();
		?>
		<div class="rses-verify-signed" <?php echo \RelataSoft\SecureElectionSuite\I18n\Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<h3><?php esc_html_e( 'Verify signed election results', 'relatasoft-secure-election-suite' ); ?></h3>
			<p><?php esc_html_e( 'Paste a signed-results.json produced after threshold decryption. Verification uses only the public key embedded in the file — no private key or server trust required.', 'relatasoft-secure-election-suite' ); ?></p>

			<?php if ( is_array( $rses_result ) ) : ?>
				<div class="rses-panel <?php echo $rses_result['valid'] ? 'rses-panel-success' : 'rses-panel-warning'; ?>">
					<p>
						<strong>
							<?php
							echo $rses_result['valid']
								? esc_html__( 'Signature VALID — results match the election public key.', 'relatasoft-secure-election-suite' )
								: esc_html__( 'Signature INVALID or package inconsistent.', 'relatasoft-secure-election-suite' );
							?>
						</strong>
					</p>
					<?php if ( ! empty( $rses_result['errors'] ) ) : ?>
						<ul>
							<?php foreach ( $rses_result['errors'] as $rses_err ) : ?>
								<li><?php echo esc_html( (string) $rses_err ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'rses_verify_signed_public', '_rses_nonce' ); ?>
				<input type="hidden" name="rses_verify_signed_public" value="1" />
				<p>
					<label for="rses_signed_json_public"><?php esc_html_e( 'Signed results JSON', 'relatasoft-secure-election-suite' ); ?></label><br />
					<textarea name="rses_signed_json" id="rses_signed_json_public" rows="14" class="large-text code" required></textarea>
				</p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Verify signature', 'relatasoft-secure-election-suite' ); ?></button></p>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
