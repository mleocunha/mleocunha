<?php
/**
 * Export manifest builder.
 *
 * @package RelataSoft\SecureElectionSuite\Exports
 */

namespace RelataSoft\SecureElectionSuite\Exports;

defined( 'ABSPATH' ) || exit;

/**
 * Builds export manifests with checksums.
 */
class ManifestBuilder {

	/**
	 * Build key export manifest.
	 *
	 * @param int                 $key_id     Key ID.
	 * @param array<string,mixed> $public_key Public key data.
	 * @return array<string,mixed>
	 */
	public static function rses_build_key_manifest( int $key_id, array $public_key ): array {
		return array(
			'version'       => RSES_VERSION,
			'type'          => 'key_export',
			'key_id'        => $key_id,
			'exported_at'   => gmdate( 'c' ),
			'source_site'   => get_site_url(),
			'public_key_hash' => HashService::rses_hash_json( $public_key ),
		);
	}

	/**
	 * Build voting export manifest.
	 *
	 * @param int                 $election_id Election ID.
	 * @param int                 $round_id    Round ID.
	 * @param array<string,mixed> $metadata    Additional metadata.
	 * @return array<string,mixed>
	 */
	public static function rses_build_voting_manifest( int $election_id, int $round_id, array $metadata = array() ): array {
		return array_merge(
			array(
				'version'     => RSES_VERSION,
				'type'        => 'voting_export',
				'election_id' => $election_id,
				'round_id'    => $round_id,
				'exported_at' => gmdate( 'c' ),
				'source_site' => get_site_url(),
			),
			$metadata
		);
	}

	/**
	 * Build checksums for export files.
	 *
	 * @param array<string,string> $files File contents map.
	 * @return array<string,string>
	 */
	public static function rses_build_checksums( array $files ): array {
		$rses_checksums = array();

		foreach ( $files as $rses_path => $rses_content ) {
			$rses_checksums[ $rses_path ] = hash( 'sha256', (string) $rses_content );
		}

		return $rses_checksums;
	}
}
