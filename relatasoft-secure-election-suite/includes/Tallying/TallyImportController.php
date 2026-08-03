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
				// Some browsers save a JSON package with a .zip extension; try JSON if ZIP had no known members.
				if ( empty( $rses_manifest['public_key'] ) && empty( $rses_manifest['encrypted_tallies'] ) && empty( $rses_manifest['manifest'] ) ) {
					$rses_head = (string) file_get_contents( $rses_tmp, false, null, 0, 1 );
					if ( '{' === $rses_head ) {
						$rses_manifest = self::rses_parse_json_import( $rses_tmp );
					}
				}
			} elseif ( str_ends_with( $rses_lower, '.json' ) ) {
				$rses_manifest = self::rses_parse_json_import( $rses_tmp );
			} else {
				wp_die( esc_html__( 'Unsupported file format. Use ZIP or JSON.', 'relatasoft-secure-election-suite' ) );
			}

			if ( empty( $rses_manifest['public_key'] ) && empty( $rses_manifest['encrypted_tallies'] ) && empty( $rses_manifest['manifest'] ) ) {
				wp_die(
					esc_html__( 'Failed to parse import file. The upload is not a Voting Export package (missing manifest/public-key/tallies). Re-download ZIP from Voting Export.', 'relatasoft-secure-election-suite' ),
					esc_html__( 'Tally Import Error', 'relatasoft-secure-election-suite' ),
					array( 'response' => 400 )
				);
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
		$rses_open = $rses_zip->open( $tmp_path );
		if ( true !== $rses_open ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: ZipArchive open error code */
						__( 'Failed to open ZIP import file (ZipArchive code %s).', 'relatasoft-secure-election-suite' ),
						(string) $rses_open
					)
				)
			);
		}

		$rses_by_base = self::rses_zip_index_by_basename( $rses_zip );
		$rses_names   = array_keys( $rses_by_base );

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
			$rses_base = strtolower( $rses_file );
			if ( ! isset( $rses_by_base[ $rses_base ] ) ) {
				continue;
			}
			$rses_idx  = (int) $rses_by_base[ $rses_base ]['index'];
			$rses_size = (int) $rses_by_base[ $rses_base ]['size'];
			$rses_path = (string) $rses_by_base[ $rses_base ]['name'];

			if ( $rses_size > self::RSES_MAX_ZIP_MEMBER_BYTES ) {
				$rses_zip->close();
				wp_die(
					esc_html(
						sprintf(
							/* translators: 1: zip entry name, 2: size in bytes */
							__( 'ZIP entry “%1$s” is too large to load (%2$s bytes). Re-export with plugin 1.0.27.2+ or contact support.', 'relatasoft-secure-election-suite' ),
							$rses_path,
							(string) $rses_size
						)
					)
				);
			}

			$rses_raw = $rses_zip->getFromIndex( $rses_idx );
			if ( false === $rses_raw || '' === $rses_raw ) {
				continue;
			}
			$rses_raw = self::rses_strip_utf8_bom( (string) $rses_raw );
			$rses_decoded = json_decode( $rses_raw, true );
			if ( is_array( $rses_decoded ) ) {
				$rses_manifest[ $rses_key ] = $rses_decoded;
			} else {
				$rses_manifest['_json_errors'][ $rses_path ] = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'json_error';
			}
			unset( $rses_raw, $rses_decoded );
		}

		// Never load encrypted-votes.json into PHP — size + checksums.json only.
		if ( isset( $rses_by_base['encrypted-votes.json'] ) ) {
			$rses_bytes = (int) $rses_by_base['encrypted-votes.json']['size'];
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

		// Single JSON package stored inside a ZIP (manual re-pack).
		if ( empty( $rses_manifest['public_key'] ) && empty( $rses_manifest['encrypted_tallies'] ) ) {
			foreach ( $rses_by_base as $rses_base => $rses_info ) {
				if ( ! str_ends_with( $rses_base, '.json' ) || 'encrypted-votes.json' === $rses_base ) {
					continue;
				}
				if ( (int) $rses_info['size'] > self::RSES_MAX_JSON_IMPORT_BYTES ) {
					continue;
				}
				$rses_raw = $rses_zip->getFromIndex( (int) $rses_info['index'] );
				if ( ! is_string( $rses_raw ) || '' === $rses_raw ) {
					continue;
				}
				$rses_raw = self::rses_strip_utf8_bom( $rses_raw );
				if ( ! str_starts_with( ltrim( $rses_raw ), '{' ) ) {
					continue;
				}
				$rses_stripped = self::rses_strip_top_level_json_key( $rses_raw, 'encrypted_votes' );
				unset( $rses_raw );
				if ( null === $rses_stripped ) {
					continue;
				}
				$rses_nested = json_decode( $rses_stripped['json'], true );
				if ( ! is_array( $rses_nested ) ) {
					continue;
				}
				if ( ! empty( $rses_stripped['present'] ) ) {
					$rses_nested['encrypted_votes_meta'] = array(
						'present' => true,
						'bytes'   => (int) $rses_stripped['bytes'],
						'sha256'  => (string) $rses_stripped['sha256'],
						'omitted' => true,
					);
				}
				unset( $rses_nested['encrypted_votes'], $rses_stripped );
				if ( ! empty( $rses_nested['public_key'] ) || ! empty( $rses_nested['encrypted_tallies'] ) ) {
					$rses_manifest = $rses_nested;
					break;
				}
			}
		}

		$rses_zip->close();

		if ( empty( $rses_manifest['public_key'] ) && empty( $rses_manifest['encrypted_tallies'] ) && empty( $rses_manifest['manifest'] ) ) {
			$rses_list = ! empty( $rses_names ) ? implode( ', ', array_slice( $rses_names, 0, 40 ) ) : '(empty archive)';
			$rses_json_errs = '';
			if ( ! empty( $rses_manifest['_json_errors'] ) && is_array( $rses_manifest['_json_errors'] ) ) {
				$rses_json_errs = ' JSON: ' . wp_json_encode( $rses_manifest['_json_errors'] );
			}
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: number of zip entries, 2: entry name list */
						__( 'Failed to parse import file. ZIP has %1$d entries but no Voting Export members (manifest.json, public-key.json, encrypted-tallies.json). Found: %2$s', 'relatasoft-secure-election-suite' ),
						count( $rses_names ),
						$rses_list
					) . $rses_json_errs
				),
				esc_html__( 'Tally Import Error', 'relatasoft-secure-election-suite' ),
				array( 'response' => 400 )
			);
		}

		unset( $rses_manifest['_json_errors'] );

		return $rses_manifest;
	}

	/**
	 * Index ZIP members by lowercased basename (supports subfolders / odd paths).
	 *
	 * @param \ZipArchive $zip Open archive.
	 * @return array<string,array{index:int,size:int,name:string}>
	 */
	private static function rses_zip_index_by_basename( \ZipArchive $zip ): array {
		$rses_out = array();
		$rses_n   = (int) $zip->numFiles;

		for ( $rses_i = 0; $rses_i < $rses_n; $rses_i++ ) {
			$rses_stat = $zip->statIndex( $rses_i );
			if ( ! is_array( $rses_stat ) || empty( $rses_stat['name'] ) ) {
				continue;
			}
			$rses_name = str_replace( '\\', '/', (string) $rses_stat['name'] );
			if ( str_ends_with( $rses_name, '/' ) ) {
				continue;
			}
			// Skip macOS resource forks.
			if ( str_contains( $rses_name, '__MACOSX/' ) || str_starts_with( basename( $rses_name ), '._' ) ) {
				continue;
			}
			$rses_base = strtolower( basename( $rses_name ) );
			if ( '' === $rses_base ) {
				continue;
			}
			// Prefer shallowest path if duplicates.
			if ( isset( $rses_out[ $rses_base ] ) ) {
				$rses_prev = substr_count( (string) $rses_out[ $rses_base ]['name'], '/' );
				$rses_cur  = substr_count( $rses_name, '/' );
				if ( $rses_cur >= $rses_prev ) {
					continue;
				}
			}
			$rses_out[ $rses_base ] = array(
				'index' => $rses_i,
				'size'  => (int) ( $rses_stat['size'] ?? 0 ),
				'name'  => $rses_name,
			);
		}

		return $rses_out;
	}

	/**
	 * @param string $raw Raw bytes.
	 */
	private static function rses_strip_utf8_bom( string $raw ): string {
		if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			return substr( $raw, 3 );
		}
		return $raw;
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
