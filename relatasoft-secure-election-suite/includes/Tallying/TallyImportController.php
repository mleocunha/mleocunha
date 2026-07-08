<?php
/**
 * Tally import controller.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\Exports\ManifestBuilder;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\Security\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Handles tally import from ZIP/JSON.
 */
class TallyImportController {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_tally_import', array( self::class, 'rses_handle_import' ) );
	}

	/**
	 * Handle tally import upload.
	 */
	public static function rses_handle_import(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_TALLY_IMPORT );
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_TALLYING );

		if ( empty( $_FILES['rses_import_file']['tmp_name'] ) ) {
			wp_die( esc_html__( 'No file uploaded.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_filename = sanitize_file_name( wp_unslash( $_FILES['rses_import_file']['name'] ) );
		$rses_tmp      = $_FILES['rses_import_file']['tmp_name'];

		$rses_manifest = array();

		if ( str_ends_with( $rses_filename, '.zip' ) ) {
			$rses_manifest = self::rses_parse_zip_import( $rses_tmp );
		} elseif ( str_ends_with( $rses_filename, '.json' ) ) {
			$rses_content  = file_get_contents( $rses_tmp );
			$rses_manifest = json_decode( (string) $rses_content, true ) ?: array();
		} else {
			wp_die( esc_html__( 'Unsupported file format. Use ZIP or JSON.', 'relatasoft-secure-election-suite' ) );
		}

		if ( empty( $rses_manifest ) ) {
			wp_die( esc_html__( 'Failed to parse import file.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_validation = self::rses_validate_import( $rses_manifest );

		$rses_import_id = TallyImportRepository::rses_create(
			array(
				'source_site_url'      => $rses_manifest['manifest']['source_site'] ?? $rses_manifest['source_site'] ?? null,
				'election_external_id' => (string) ( $rses_manifest['election']['id'] ?? $rses_manifest['election_id'] ?? '' ),
				'round_external_id'    => (string) ( $rses_manifest['round']['id'] ?? $rses_manifest['round_id'] ?? '' ),
				'import_manifest_json' => wp_json_encode( $rses_manifest ),
				'import_hash'          => HashService::rses_hash_json( $rses_manifest ),
				'status'               => $rses_validation['valid'] ? 'verified' : 'rejected',
			)
		);

		AuditLogger::rses_log(
			'tally_import',
			'tally_import',
			$rses_import_id,
			array(
				'status' => $rses_validation['valid'] ? 'verified' : 'rejected',
				'errors' => $rses_validation['errors'],
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=rses-tally-import&rses_imported=' . $rses_import_id ) );
		exit;
	}

	/**
	 * Parse ZIP import file.
	 *
	 * @param string $tmp_path Temp file path.
	 * @return array<string,mixed>
	 */
	private static function rses_parse_zip_import( string $tmp_path ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZIP import requires ZipArchive.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_zip = new \ZipArchive();
		$rses_manifest = array();

		if ( true === $rses_zip->open( $tmp_path ) ) {
			$rses_manifest_json = $rses_zip->getFromName( 'manifest.json' );
			if ( $rses_manifest_json ) {
				$rses_manifest['manifest'] = json_decode( $rses_manifest_json, true );
			}

			$rses_public = $rses_zip->getFromName( 'public-key.json' );
			if ( $rses_public ) {
				$rses_manifest['public_key'] = json_decode( $rses_public, true );
			}

			$rses_election = $rses_zip->getFromName( 'election.json' );
			if ( $rses_election ) {
				$rses_manifest['election'] = json_decode( $rses_election, true );
			}

			$rses_round = $rses_zip->getFromName( 'round.json' );
			if ( $rses_round ) {
				$rses_manifest['round'] = json_decode( $rses_round, true );
			}

			$rses_tallies = $rses_zip->getFromName( 'encrypted-tallies.json' );
			if ( $rses_tallies ) {
				$rses_manifest['encrypted_tallies'] = json_decode( $rses_tallies, true );
			}

			$rses_votes = $rses_zip->getFromName( 'encrypted-votes.json' );
			if ( $rses_votes ) {
				$rses_manifest['encrypted_votes'] = json_decode( $rses_votes, true );
			}

			$rses_checksums = $rses_zip->getFromName( 'checksums.json' );
			if ( $rses_checksums ) {
				$rses_manifest['checksums'] = json_decode( $rses_checksums, true );
			}

			$rses_zip->close();
		}

		return $rses_manifest;
	}

	/**
	 * Validate import manifest and checksums.
	 *
	 * @param array<string,mixed> $manifest Import data.
	 * @return array{valid:bool,errors:array<int,string>}
	 */
	public static function rses_validate_import( array $manifest ): array {
		$rses_errors = array();

		$rses_pk = $manifest['public_key'] ?? array();
		foreach ( array( 'p', 'q', 'g', 'y' ) as $rses_field ) {
			if ( empty( $rses_pk[ $rses_field ] ) ) {
				$rses_errors[] = sprintf(
					/* translators: %s: field name */
					__( 'Missing public key field: %s', 'relatasoft-secure-election-suite' ),
					$rses_field
				);
			}
		}

		$rses_tallies = $manifest['encrypted_tallies'] ?? array();
		$rses_votes   = $manifest['encrypted_votes'] ?? array();

		if ( empty( $rses_tallies ) && empty( $rses_votes ) ) {
			$rses_errors[] = __( 'No encrypted votes or tallies found.', 'relatasoft-secure-election-suite' );
		}

		foreach ( $rses_tallies as $rses_idx => $rses_tally ) {
			if ( empty( $rses_tally['aggregate_alpha'] ) || empty( $rses_tally['aggregate_beta'] ) ) {
				$rses_errors[] = sprintf(
					/* translators: %d: tally index */
					__( 'Invalid ciphertext at tally index %d', 'relatasoft-secure-election-suite' ),
					$rses_idx
				);
			}
		}

		return array(
			'valid'  => empty( $rses_errors ),
			'errors' => $rses_errors,
		);
	}
}
