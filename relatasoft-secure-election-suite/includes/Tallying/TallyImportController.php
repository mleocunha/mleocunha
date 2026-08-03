<?php
/**
 * Tally import controller.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Exports\HashService;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Handles tally import from ZIP/JSON.
 *
 * Homomorphic decryption only needs public_key + encrypted_tallies. Loading the
 * full encrypted_votes array (and re-encoding it into LONGTEXT) exhausts 128M
 * PHP memory on real elections — that shows up as a blank white admin screen.
 */
class TallyImportController {

	/**
	 * Max bytes for a single-JSON import loaded into PHP string memory.
	 * Larger packages must use ZIP.
	 */
	private const RSES_MAX_JSON_IMPORT_BYTES = 8388608; // 8 MiB

	/**
	 * Max bytes for a ZIP member loaded via getFromName (never used for votes).
	 */
	private const RSES_MAX_ZIP_MEMBER_BYTES = 2097152; // 2 MiB

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
		// Memory exhaustion is not catchable — raise before any ZIP work.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@ini_set( 'memory_limit', '256M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		// Unblock admin if a previous attempt stored a multi‑MB manifest.
		TallyImportRepository::rses_purge_oversized_manifests();

		try {
			Capability::rses_require_tally_admin();
			Nonce::rses_verify_or_die( Nonce::RSES_ACTION_TALLY_IMPORT );
			ModeLock::rses_require_mode( ModeLock::RSES_MODE_TALLYING );

			if ( empty( $_FILES['rses_import_file']['tmp_name'] ) ) {
				wp_die( esc_html__( 'No file uploaded.', 'relatasoft-secure-election-suite' ) );
			}

			$rses_upload_error = (int) ( $_FILES['rses_import_file']['error'] ?? UPLOAD_ERR_OK );
			if ( UPLOAD_ERR_OK !== $rses_upload_error ) {
				wp_die(
					esc_html(
						sprintf(
							/* translators: %d: PHP upload error code */
							__( 'Upload failed (PHP error code %d). Check upload_max_filesize / post_max_size.', 'relatasoft-secure-election-suite' ),
							$rses_upload_error
						)
					)
				);
			}

			$rses_filename = sanitize_file_name( wp_unslash( $_FILES['rses_import_file']['name'] ) );
			$rses_tmp      = (string) $_FILES['rses_import_file']['tmp_name'];
			$rses_lower    = strtolower( $rses_filename );

			if ( str_ends_with( $rses_lower, '.zip' ) ) {
				$rses_manifest = self::rses_parse_zip_import( $rses_tmp );
			} elseif ( str_ends_with( $rses_lower, '.json' ) ) {
				$rses_manifest = self::rses_parse_json_import( $rses_tmp );
			} else {
				wp_die( esc_html__( 'Unsupported file format. Use ZIP or JSON.', 'relatasoft-secure-election-suite' ) );
			}

			if ( empty( $rses_manifest ) ) {
				wp_die( esc_html__( 'Failed to parse import file.', 'relatasoft-secure-election-suite' ) );
			}

			// Never persist the full ciphertext list — decryption does not need it.
			unset( $rses_manifest['encrypted_votes'] );

			$rses_validation = self::rses_validate_import( $rses_manifest );

			$rses_manifest_json = wp_json_encode( $rses_manifest, JSON_UNESCAPED_SLASHES );
			if ( false === $rses_manifest_json ) {
				wp_die( esc_html__( 'Failed to encode import manifest for storage.', 'relatasoft-secure-election-suite' ) );
			}

			$rses_import_id = TallyImportRepository::rses_create(
				array(
					'source_site_url'      => $rses_manifest['manifest']['source_site'] ?? $rses_manifest['source_site'] ?? null,
					'election_external_id' => (string) ( $rses_manifest['election']['id'] ?? $rses_manifest['election_id'] ?? '' ),
					'round_external_id'    => (string) ( $rses_manifest['round']['id'] ?? $rses_manifest['round_id'] ?? '' ),
					'import_manifest_json' => $rses_manifest_json,
					'import_hash'          => HashService::rses_hash_json( $rses_manifest ),
					'status'               => $rses_validation['valid'] ? 'verified' : 'rejected',
				)
			);

			if ( $rses_import_id < 1 ) {
				wp_die( esc_html__( 'Failed to store tally import in the database.', 'relatasoft-secure-election-suite' ) );
			}

			AuditLogger::rses_log(
				'tally_import',
				'tally_import',
				$rses_import_id,
				array(
					'status' => $rses_validation['valid'] ? 'verified' : 'rejected',
					'errors' => $rses_validation['errors'],
				)
			);

			set_transient(
				'rses_tally_import_flash_' . $rses_import_id,
				array(
					'status' => $rses_validation['valid'] ? 'verified' : 'rejected',
					'errors' => $rses_validation['errors'],
					'plugin' => RSES_VERSION,
				),
				10 * MINUTE_IN_SECONDS
			);

			wp_safe_redirect( admin_url( 'admin.php?page=rses-tally-import&rses_imported=' . $rses_import_id ) );
			exit;
		} catch ( \Throwable $rses_e ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: error message */
						__( 'Tally import failed: %s', 'relatasoft-secure-election-suite' ),
						$rses_e->getMessage()
					)
				),
				esc_html__( 'Tally Import Error', 'relatasoft-secure-election-suite' ),
				array( 'response' => 500 )
			);
		}
	}

	/**
	 * Parse ZIP import without loading encrypted-votes.json into RAM.
	 *
	 * @param string $tmp_path Temp file path.
	 * @return array<string,mixed>
	 */
	private static function rses_parse_zip_import( string $tmp_path ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZIP import requires ZipArchive.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_zip = new \ZipArchive();
		if ( true !== $rses_zip->open( $tmp_path ) ) {
			wp_die( esc_html__( 'Failed to open ZIP import file.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_manifest = array();

		$rses_map = array(
			'manifest.json'          => 'manifest',
			'public-key.json'        => 'public_key',
			'election.json'          => 'election',
			'round.json'             => 'round',
			'ballot.json'            => 'ballot',
			'encrypted-tallies.json' => 'encrypted_tallies',
			'audit.json'             => 'audit',
			'checksums.json'         => 'checksums',
		);

		foreach ( $rses_map as $rses_file => $rses_key ) {
			$rses_idx = $rses_zip->locateName( $rses_file );
			if ( false === $rses_idx ) {
				continue;
			}
			$rses_stat = $rses_zip->statIndex( $rses_idx );
			$rses_size = is_array( $rses_stat ) ? (int) ( $rses_stat['size'] ?? 0 ) : 0;
			if ( $rses_size > self::RSES_MAX_ZIP_MEMBER_BYTES ) {
				$rses_zip->close();
				wp_die(
					esc_html(
						sprintf(
							/* translators: 1: zip entry name, 2: size in bytes */
							__( 'ZIP entry “%1$s” is too large to load (%2$s bytes). Re-export with plugin 1.0.27.2+ or contact support.', 'relatasoft-secure-election-suite' ),
							$rses_file,
							(string) $rses_size
						)
					)
				);
			}

			$rses_raw = $rses_zip->getFromIndex( $rses_idx );
			if ( false === $rses_raw || '' === $rses_raw ) {
				continue;
			}
			$rses_decoded = json_decode( $rses_raw, true );
			if ( is_array( $rses_decoded ) ) {
				$rses_manifest[ $rses_key ] = $rses_decoded;
			}
			unset( $rses_raw, $rses_decoded );
		}

		// Never getFromName/getFromIndex encrypted-votes.json — that OOMs 128M hosts.
		// Trust checksums.json (written at export) and record size from ZIP stat only.
		$rses_votes_idx = $rses_zip->locateName( 'encrypted-votes.json' );
		if ( false !== $rses_votes_idx ) {
			$rses_stat  = $rses_zip->statIndex( $rses_votes_idx );
			$rses_bytes = is_array( $rses_stat ) ? (int) ( $rses_stat['size'] ?? 0 ) : 0;
			$rses_sha   = '';
			if ( ! empty( $rses_manifest['checksums']['encrypted-votes.json'] ) && is_string( $rses_manifest['checksums']['encrypted-votes.json'] ) ) {
				$rses_sha = $rses_manifest['checksums']['encrypted-votes.json'];
			}

			$rses_manifest['encrypted_votes_meta'] = array(
				'present' => true,
				'bytes'   => $rses_bytes,
				'sha256'  => $rses_sha,
				'omitted' => true,
				'source'  => 'checksums.json',
			);
		}

		$rses_zip->close();

		return $rses_manifest;
	}

	/**
	 * Parse a single-JSON voting export; strip encrypted_votes before json_decode.
	 *
	 * @param string $tmp_path Temp file path.
	 * @return array<string,mixed>
	 */
	private static function rses_parse_json_import( string $tmp_path ): array {
		$rses_size = filesize( $tmp_path );
		if ( false !== $rses_size && $rses_size > self::RSES_MAX_JSON_IMPORT_BYTES ) {
			wp_die(
				esc_html__(
					'This JSON export is too large to import safely on this server. Use the ZIP export from Voting Export instead (recommended).',
					'relatasoft-secure-election-suite'
				)
			);
		}

		$rses_raw = file_get_contents( $tmp_path );
		if ( false === $rses_raw || '' === $rses_raw ) {
			wp_die( esc_html__( 'Failed to parse import file.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_stripped = self::rses_strip_top_level_json_key( $rses_raw, 'encrypted_votes' );
		unset( $rses_raw );
		if ( null === $rses_stripped ) {
			wp_die( esc_html__( 'Failed to parse import file.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_manifest = json_decode( $rses_stripped['json'], true );
		unset( $rses_stripped['json'] );
		if ( ! is_array( $rses_manifest ) ) {
			wp_die( esc_html__( 'Failed to parse import file.', 'relatasoft-secure-election-suite' ) );
		}

		if ( ! empty( $rses_stripped['present'] ) ) {
			$rses_manifest['encrypted_votes_meta'] = array(
				'present' => true,
				'bytes'   => (int) $rses_stripped['bytes'],
				'sha256'  => (string) $rses_stripped['sha256'],
				'omitted' => true,
			);
		}

		return $rses_manifest;
	}

	/**
	 * Remove one top-level object key from a JSON object string without decoding it.
	 *
	 * @param string $json JSON object text.
	 * @param string $key  Key to omit.
	 * @return array{json:string,present:bool,bytes:int,sha256:string}|null
	 */
	private static function rses_strip_top_level_json_key( string $json, string $key ): ?array {
		$rses_needle = '"' . $key . '"';
		$rses_len    = strlen( $json );
		$rses_depth  = 0;
		$rses_in_str = false;
		$rses_escape = false;

		for ( $rses_i = 0; $rses_i < $rses_len; $rses_i++ ) {
			$rses_ch = $json[ $rses_i ];

			if ( $rses_in_str ) {
				if ( $rses_escape ) {
					$rses_escape = false;
				} elseif ( '\\' === $rses_ch ) {
					$rses_escape = true;
				} elseif ( '"' === $rses_ch ) {
					$rses_in_str = false;
				}
				continue;
			}

			if ( '"' === $rses_ch ) {
				// Top-level object keys sit at depth 1.
				if ( 1 === $rses_depth && 0 === substr_compare( $json, $rses_needle, $rses_i, strlen( $rses_needle ) ) ) {
					$rses_key_start = $rses_i;
					$rses_j         = $rses_i + strlen( $rses_needle );
					while ( $rses_j < $rses_len && ctype_space( $json[ $rses_j ] ) ) {
						++$rses_j;
					}
					if ( $rses_j >= $rses_len || ':' !== $json[ $rses_j ] ) {
						$rses_in_str = true;
						continue;
					}
					++$rses_j;
					while ( $rses_j < $rses_len && ctype_space( $json[ $rses_j ] ) ) {
						++$rses_j;
					}
					$rses_value_start = $rses_j;
					$rses_value_end   = self::rses_end_of_json_value( $json, $rses_value_start );
					if ( null === $rses_value_end ) {
						return null;
					}

					$rses_value = substr( $json, $rses_value_start, $rses_value_end - $rses_value_start );
					$rses_after = $rses_value_end;
					while ( $rses_after < $rses_len && ctype_space( $json[ $rses_after ] ) ) {
						++$rses_after;
					}
					if ( $rses_after < $rses_len && ',' === $json[ $rses_after ] ) {
						++$rses_after;
					} else {
						// Remove a comma immediately before the key if this was the last property.
						$rses_before = $rses_key_start;
						while ( $rses_before > 0 && ctype_space( $json[ $rses_before - 1 ] ) ) {
							--$rses_before;
						}
						if ( $rses_before > 0 && ',' === $json[ $rses_before - 1 ] ) {
							$rses_key_start = $rses_before - 1;
						}
					}

					$rses_slim = substr( $json, 0, $rses_key_start ) . substr( $json, $rses_after );
					return array(
						'json'    => $rses_slim,
						'present' => true,
						'bytes'   => strlen( $rses_value ),
						'sha256'  => hash( 'sha256', $rses_value ),
					);
				}
				$rses_in_str = true;
				continue;
			}

			if ( '{' === $rses_ch || '[' === $rses_ch ) {
				++$rses_depth;
			} elseif ( '}' === $rses_ch || ']' === $rses_ch ) {
				--$rses_depth;
			}
		}

		return array(
			'json'    => $json,
			'present' => false,
			'bytes'   => 0,
			'sha256'  => '',
		);
	}

	/**
	 * Return index after a JSON value starting at $start, or null.
	 *
	 * @param string $json  Full JSON text.
	 * @param int    $start Value start offset.
	 */
	private static function rses_end_of_json_value( string $json, int $start ): ?int {
		$rses_len = strlen( $json );
		if ( $start >= $rses_len ) {
			return null;
		}

		$rses_ch = $json[ $start ];
		if ( '"' === $rses_ch ) {
			$rses_esc = false;
			for ( $rses_i = $start + 1; $rses_i < $rses_len; $rses_i++ ) {
				$rses_c = $json[ $rses_i ];
				if ( $rses_esc ) {
					$rses_esc = false;
					continue;
				}
				if ( '\\' === $rses_c ) {
					$rses_esc = true;
					continue;
				}
				if ( '"' === $rses_c ) {
					return $rses_i + 1;
				}
			}
			return null;
		}

		if ( '{' === $rses_ch || '[' === $rses_ch ) {
			$rses_open   = $rses_ch;
			$rses_close  = '{' === $rses_ch ? '}' : ']';
			$rses_depth  = 0;
			$rses_in_str = false;
			$rses_esc    = false;
			for ( $rses_i = $start; $rses_i < $rses_len; $rses_i++ ) {
				$rses_c = $json[ $rses_i ];
				if ( $rses_in_str ) {
					if ( $rses_esc ) {
						$rses_esc = false;
					} elseif ( '\\' === $rses_c ) {
						$rses_esc = true;
					} elseif ( '"' === $rses_c ) {
						$rses_in_str = false;
					}
					continue;
				}
				if ( '"' === $rses_c ) {
					$rses_in_str = true;
					continue;
				}
				if ( $rses_c === $rses_open ) {
					++$rses_depth;
				} elseif ( $rses_c === $rses_close ) {
					--$rses_depth;
					if ( 0 === $rses_depth ) {
						return $rses_i + 1;
					}
				}
			}
			return null;
		}

		for ( $rses_i = $start; $rses_i < $rses_len; $rses_i++ ) {
			$rses_c = $json[ $rses_i ];
			if ( ',' === $rses_c || '}' === $rses_c || ']' === $rses_c || ctype_space( $rses_c ) ) {
				return $rses_i;
			}
		}
		return $rses_len;
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

		$rses_tallies      = $manifest['encrypted_tallies'] ?? array();
		$rses_votes_meta   = $manifest['encrypted_votes_meta'] ?? null;
		$rses_votes_present = is_array( $rses_votes_meta ) && ! empty( $rses_votes_meta['present'] );
		$rses_votes_embedded = ! empty( $manifest['encrypted_votes'] ) && is_array( $manifest['encrypted_votes'] );

		if ( empty( $rses_tallies ) && ! $rses_votes_present && ! $rses_votes_embedded ) {
			$rses_errors[] = __( 'No encrypted votes or tallies found.', 'relatasoft-secure-election-suite' );
		}

		if ( empty( $rses_tallies ) || ! is_array( $rses_tallies ) ) {
			$rses_errors[] = __( 'Encrypted tallies are required for decryption. Re-export from the voting site after closing the round.', 'relatasoft-secure-election-suite' );
		} else {
			foreach ( $rses_tallies as $rses_idx => $rses_tally ) {
				if ( ! is_array( $rses_tally ) || empty( $rses_tally['aggregate_alpha'] ) || empty( $rses_tally['aggregate_beta'] ) ) {
					$rses_errors[] = sprintf(
						/* translators: %d: tally index */
						__( 'Invalid ciphertext at tally index %d', 'relatasoft-secure-election-suite' ),
						$rses_idx
					);
				}
			}
		}

		return array(
			'valid'  => empty( $rses_errors ),
			'errors' => $rses_errors,
		);
	}
}
