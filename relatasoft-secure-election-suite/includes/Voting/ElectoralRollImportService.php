<?php
/**
 * Electoral roll (cadastro eleitoral) CSV import.
 *
 * Spreadsheet model matches WooCommerce-style user exports used for test rolls,
 * plus a final "password" column accepted as-is (no WP strength checks).
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Imports electors from the electoral registration CSV.
 */
class ElectoralRollImportService {

	/**
	 * Final column: plaintext password (accepted without WordPress questioning).
	 */
	public const PASSWORD_COLUMN = 'password';

	/**
	 * Core + profile columns from the electoral spreadsheet (in order).
	 *
	 * @var list<string>
	 */
	public const CORE_COLUMNS = array(
		'user_login',
		'user_email',
		'display_name',
		'role',
		'first_name',
		'last_name',
	);

	/**
	 * User-meta columns stored as-is from the spreadsheet.
	 *
	 * @var list<string>
	 */
	public const META_COLUMNS = array(
		'billing_first_name',
		'billing_last_name',
		'billing_company',
		'billing_email',
		'billing_phone',
		'billing_country',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'shipping_first_name',
		'shipping_last_name',
		'shipping_company',
		'shipping_country',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
	);

	/**
	 * Soft cap per upload (sheet used in testing has 5000 rows).
	 */
	public const MAX_ROWS = 10000;

	/**
	 * Explicit CSV escape for PHP 8.4+ (historical default; must be passed).
	 */
	private const CSV_ESCAPE = '\\';

	/**
	 * Full expected header list: spreadsheet columns + password on the right.
	 *
	 * @return list<string>
	 */
	public static function rses_expected_headers(): array {
		return array_merge( self::CORE_COLUMNS, self::META_COLUMNS, array( self::PASSWORD_COLUMN ) );
	}

	/**
	 * Localized download basename for the example CSV (e.g. exemplo.csv).
	 */
	public static function rses_sample_filename(): string {
		$locale = \RelataSoft\SecureElectionSuite\I18n\LocaleResolver::rses_resolve();
		$stem   = self::rses_example_stem_for_locale( $locale );
		return $stem . '.csv';
	}

	/**
	 * Word for “example” used in the sample filename, by UI locale.
	 */
	public static function rses_example_stem_for_locale( string $locale ): string {
		$map = array(
			'pt_BR' => 'exemplo',
			'pt_PT' => 'exemplo',
			'en_US' => 'example',
			'es_ES' => 'ejemplo',
			'ca'    => 'exemple',
			'fr_FR' => 'exemple',
			'de_DE' => 'beispiel',
			'nl_NL' => 'voorbeeld',
			'ru_RU' => 'primer',
			'zh_CN' => 'lizi',
			'ar'    => 'mithal',
			'he_IL' => 'dugma',
		);

		if ( isset( $map[ $locale ] ) ) {
			return $map[ $locale ];
		}

		$lang = strtolower( (string) strtok( $locale, '_' ) );
		foreach ( $map as $code => $stem ) {
			if ( str_starts_with( strtolower( $code ), $lang ) ) {
				return $stem;
			}
		}

		return 'example';
	}

	/**
	 * Sample CSV: one metadata (header) line + 10 data rows.
	 */
	public static function rses_sample_csv(): string {
		$headers = self::rses_expected_headers();
		$cities  = array(
			array( 'Brasília', 'DF', '70000000' ),
			array( 'Maceió', 'AL', '57007919' ),
			array( 'Manaus', 'AM', '69015838' ),
			array( 'Salvador', 'BA', '40023757' ),
			array( 'Fortaleza', 'CE', '60031676' ),
			array( 'Vitória', 'ES', '29000000' ),
			array( 'Goiânia', 'GO', '74000000' ),
			array( 'São Luís', 'MA', '65000000' ),
			array( 'Belo Horizonte', 'MG', '30000000' ),
			array( 'Belém', 'PA', '66000000' ),
		);

		$out = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		self::rses_fputcsv( $out, $headers );

		for ( $i = 1; $i <= 10; $i++ ) {
			$n     = sprintf( '%04d', $i );
			$city  = $cities[ $i - 1 ];
			$login = 'eleitor.exemplo.' . $n;
			$email = $n . '@relatasoft.com.br';
			$first = 'Eleitor';
			$last  = 'Exemplo ' . $n;
			$name  = $first . ' ' . $last;
			$phone = '119' . str_pad( (string) ( 10000000 + $i ), 8, '0', STR_PAD_LEFT );
			$street = 'Rua Exemplo ' . $i;
			$comp   = ( 0 === $i % 2 ) ? 'Apto ' . $i : 'Casa ' . $i;

			self::rses_fputcsv(
				$out,
				array(
					$login,
					$email,
					$name,
					'subscriber',
					$first,
					$last,
					$first,
					$last,
					'',
					$email,
					$phone,
					'BR',
					$street,
					$comp,
					$city[0],
					$city[1],
					$city[2],
					$first,
					$last,
					'',
					'BR',
					$street,
					$comp,
					$city[0],
					$city[1],
					$city[2],
					'senha-exemplo-' . $n,
				)
			);
		}

		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return is_string( $csv ) ? $csv : '';
	}

	/**
	 * Build a CSV report of import errors (for download).
	 *
	 * @param list<string> $errors Error messages.
	 */
	public static function rses_errors_csv( array $errors ): string {
		$out = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		self::rses_fputcsv(
			$out,
			array(
				'line',
				'error',
			)
		);

		foreach ( $errors as $message ) {
			$message = (string) $message;
			$line    = '';
			if ( preg_match( '/(?:Row|Linha)\s+(\d+)/u', $message, $m ) ) {
				$line = $m[1];
			}
			self::rses_fputcsv( $out, array( $line, $message ) );
		}

		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return is_string( $csv ) ? $csv : '';
	}

	/**
	 * Import an uploaded CSV path.
	 *
	 * @param string $file_path        Absolute path.
	 * @param bool   $update_existing  Update matching login/email.
	 * @return array{created:int,updated:int,skipped:int,errors:list<string>}
	 */
	public static function rses_import_file( string $file_path, bool $update_existing = true ): array {
		$result = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		if ( ! is_readable( $file_path ) ) {
			$result['errors'][] = __( 'CSV file could not be read.', 'relatasoft-secure-election-suite' );
			return $result;
		}

		// Large rolls can exceed the default PHP max_execution_time (e.g. 300s).
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- intentional for bulk import
			@set_time_limit( 0 );
		}

		$handle = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			$result['errors'][] = __( 'CSV file could not be opened.', 'relatasoft-secure-election-suite' );
			return $result;
		}

		$raw_header = self::rses_fgetcsv( $handle );
		if ( ! is_array( $raw_header ) || empty( $raw_header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$result['errors'][] = __( 'CSV header row is missing.', 'relatasoft-secure-election-suite' );
			return $result;
		}

		if ( isset( $raw_header[0] ) && is_string( $raw_header[0] ) ) {
			$raw_header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $raw_header[0] ) ?? $raw_header[0];
		}

		$map = self::rses_map_headers( $raw_header );
		if ( is_wp_error( $map ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$result['errors'][] = $map->get_error_message();
			return $result;
		}

		self::rses_begin_unquestioned_passwords();

		$row_num = 1;
		while ( ( $row = self::rses_fgetcsv( $handle ) ) !== false ) {
			++$row_num;

			if ( ( $row_num - 1 ) > self::MAX_ROWS ) {
				$result['errors'][] = sprintf(
					/* translators: %d: max rows */
					__( 'Import stopped: more than %d data rows.', 'relatasoft-secure-election-suite' ),
					self::MAX_ROWS
				);
				break;
			}

			if ( self::rses_row_empty( $row ) ) {
				continue;
			}

			$parsed = self::rses_parse_row( $row, $map, $row_num );
			if ( is_wp_error( $parsed ) ) {
				$result['errors'][] = $parsed->get_error_message();
				++$result['skipped'];
				continue;
			}

			$outcome = self::rses_upsert_user( $parsed, $update_existing );
			if ( is_wp_error( $outcome ) ) {
				$result['errors'][] = sprintf(
					/* translators: 1: row number, 2: error */
					__( 'Row %1$d: %2$s', 'relatasoft-secure-election-suite' ),
					$row_num,
					$outcome->get_error_message()
				);
				++$result['skipped'];
				continue;
			}

			if ( 'created' === $outcome ) {
				++$result['created'];
			} elseif ( 'updated' === $outcome ) {
				++$result['updated'];
			} else {
				++$result['skipped'];
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		self::rses_end_unquestioned_passwords();

		self::rses_log_import_audit(
			(int) $result['created'],
			(int) $result['updated'],
			(int) $result['skipped'],
			count( $result['errors'] )
		);

		return $result;
	}

	/**
	 * Audit helper shared by sync and chunked imports.
	 */
	public static function rses_log_import_audit( int $created, int $updated, int $skipped, int $errors ): void {
		AuditLogger::rses_log(
			'electoral_roll_import',
			'users',
			null,
			array(
				'command' => 'import_electoral_roll_csv',
				'created' => $created,
				'updated' => $updated,
				'skipped' => $skipped,
				'errors'  => $errors,
			)
		);
	}

	/**
	 * Validate headers, count data rows, and return the byte offset of the first data row.
	 *
	 * @return array{map:array<string,int>,byte_offset:int,total_rows:int}|\WP_Error
	 */
	public static function rses_prepare_file( string $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return new \WP_Error( 'rses_csv_read', __( 'CSV file could not be read.', 'relatasoft-secure-election-suite' ) );
		}

		$handle = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new \WP_Error( 'rses_csv_open', __( 'CSV file could not be opened.', 'relatasoft-secure-election-suite' ) );
		}

		$raw_header = self::rses_fgetcsv( $handle );
		if ( ! is_array( $raw_header ) || empty( $raw_header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'rses_csv_headers', __( 'CSV header row is missing.', 'relatasoft-secure-election-suite' ) );
		}

		if ( isset( $raw_header[0] ) && is_string( $raw_header[0] ) ) {
			$raw_header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $raw_header[0] ) ?? $raw_header[0];
		}

		$map = self::rses_map_headers( $raw_header );
		if ( is_wp_error( $map ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return $map;
		}

		$byte_offset = ftell( $handle );
		if ( false === $byte_offset ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'rses_csv_seek', __( 'Could not determine CSV data offset.', 'relatasoft-secure-election-suite' ) );
		}

		$total_rows = 0;
		while ( ( $row = self::rses_fgetcsv( $handle ) ) !== false ) {
			if ( self::rses_row_empty( $row ) ) {
				continue;
			}
			++$total_rows;
			if ( $total_rows > self::MAX_ROWS ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new \WP_Error(
					'rses_csv_max',
					sprintf(
						/* translators: 1: found rows (at least), 2: max rows */
						__( 'Import stopped: the CSV has more than %1$d data rows (limit is %2$d). Split the spreadsheet or remove duplicate content and try again.', 'relatasoft-secure-election-suite' ),
						self::MAX_ROWS,
						self::MAX_ROWS
					)
				);
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $total_rows < 1 ) {
			return new \WP_Error( 'rses_csv_empty', __( 'CSV has a header but no data rows.', 'relatasoft-secure-election-suite' ) );
		}

		return array(
			'map'         => $map,
			'byte_offset' => (int) $byte_offset,
			'total_rows'  => $total_rows,
		);
	}

	/**
	 * Process up to $limit non-empty data rows from a byte offset.
	 *
	 * @param array<string,int> $map Header map.
	 * @return array{created:int,updated:int,skipped:int,errors:list<string>,processed:int,byte_offset:int,next_row_num:int,done:bool}|\WP_Error
	 */
	public static function rses_process_batch(
		string $file_path,
		array $map,
		int $byte_offset,
		int $row_num,
		int $limit,
		bool $update_existing,
		int $max_data_rows = self::MAX_ROWS
	) {
		$result = array(
			'created'      => 0,
			'updated'      => 0,
			'skipped'      => 0,
			'errors'       => array(),
			'processed'    => 0,
			'byte_offset'  => $byte_offset,
			'next_row_num' => $row_num,
			'done'         => false,
		);

		if ( ! is_readable( $file_path ) ) {
			return new \WP_Error( 'rses_csv_read', __( 'CSV file could not be read.', 'relatasoft-secure-election-suite' ) );
		}

		$handle = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new \WP_Error( 'rses_csv_open', __( 'CSV file could not be opened.', 'relatasoft-secure-election-suite' ) );
		}

		if ( $byte_offset > 0 && 0 !== fseek( $handle, $byte_offset ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'rses_csv_seek', __( 'Could not seek in CSV file.', 'relatasoft-secure-election-suite' ) );
		}

		$limit     = max( 1, $limit );
		$max_reads = max( $limit * 4, $limit + 50 );
		$reads     = 0;
		self::rses_begin_unquestioned_passwords();

		while ( $result['processed'] < $limit && $reads < $max_reads ) {
			$row = self::rses_fgetcsv( $handle );
			++$reads;
			if ( false === $row ) {
				$result['done'] = true;
				break;
			}

			++$result['next_row_num'];
			$pos_after = ftell( $handle );
			if ( false !== $pos_after ) {
				$result['byte_offset'] = (int) $pos_after;
			}

			$data_index = $result['next_row_num'] - 1;
			if ( $data_index > $max_data_rows ) {
				$result['errors'][] = sprintf(
					/* translators: %d: max rows */
					__( 'Import stopped: more than %d data rows.', 'relatasoft-secure-election-suite' ),
					$max_data_rows
				);
				$result['done'] = true;
				break;
			}

			if ( self::rses_row_empty( $row ) ) {
				continue;
			}

			++$result['processed'];

			$parsed = self::rses_parse_row( $row, $map, $result['next_row_num'] );
			if ( is_wp_error( $parsed ) ) {
				$result['errors'][] = $parsed->get_error_message();
				++$result['skipped'];
				continue;
			}

			$outcome = self::rses_upsert_user( $parsed, $update_existing );
			if ( is_wp_error( $outcome ) ) {
				$result['errors'][] = sprintf(
					/* translators: 1: row number, 2: error */
					__( 'Row %1$d: %2$s', 'relatasoft-secure-election-suite' ),
					$result['next_row_num'],
					$outcome->get_error_message()
				);
				++$result['skipped'];
				continue;
			}

			if ( 'created' === $outcome ) {
				++$result['created'];
			} elseif ( 'updated' === $outcome ) {
				++$result['updated'];
			} else {
				++$result['skipped'];
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		self::rses_end_unquestioned_passwords();

		return $result;
	}

	/**
	 * Map spreadsheet headers. Requires password as the rightmost column.
	 *
	 * @param list<string|null> $headers Header cells.
	 * @return array<string,int>|\WP_Error
	 */
	public static function rses_map_headers( array $headers ): array|\WP_Error {
		$normalized = array();
		foreach ( $headers as $i => $cell ) {
			$normalized[ $i ] = self::rses_normalize_header( (string) $cell );
		}

		$last = count( $normalized ) - 1;
		while ( $last >= 0 && '' === $normalized[ $last ] ) {
			--$last;
		}
		if ( $last < 0 ) {
			return new \WP_Error( 'rses_csv_headers', __( 'CSV header row is empty.', 'relatasoft-secure-election-suite' ) );
		}

		if ( self::PASSWORD_COLUMN !== $normalized[ $last ] ) {
			return new \WP_Error(
				'rses_csv_password',
				sprintf(
					/* translators: %s: password column name */
					__( 'Add a final column named “%s” to the right of the electoral spreadsheet. Imported passwords are accepted as-is (no platform strength checks).', 'relatasoft-secure-election-suite' ),
					self::PASSWORD_COLUMN
				)
			);
		}

		$allowed = array_fill_keys(
			array_merge( self::CORE_COLUMNS, self::META_COLUMNS, array( self::PASSWORD_COLUMN ) ),
			true
		);

		$map = array();
		foreach ( $normalized as $index => $header ) {
			if ( '' === $header ) {
				continue;
			}
			if ( ! isset( $allowed[ $header ] ) ) {
				return new \WP_Error(
					'rses_csv_unknown',
					sprintf(
						/* translators: %s: column header */
						__( 'Unknown CSV column for electoral roll: %s', 'relatasoft-secure-election-suite' ),
						$header
					)
				);
			}
			if ( isset( $map[ $header ] ) ) {
				return new \WP_Error(
					'rses_csv_dup',
					sprintf(
						/* translators: %s: column header */
						__( 'Duplicate CSV column: %s', 'relatasoft-secure-election-suite' ),
						$header
					)
				);
			}
			$map[ $header ] = $index;
		}

		foreach ( array( 'user_login', 'user_email', self::PASSWORD_COLUMN ) as $required ) {
			if ( ! isset( $map[ $required ] ) ) {
				return new \WP_Error(
					'rses_csv_required',
					__( 'Electoral roll CSV must include user_login, user_email, and password (as the last column).', 'relatasoft-secure-election-suite' )
				);
			}
		}

		if ( $map[ self::PASSWORD_COLUMN ] !== $last ) {
			return new \WP_Error(
				'rses_csv_password_order',
				__( 'The password column must be the last column on the right.', 'relatasoft-secure-election-suite' )
			);
		}

		return $map;
	}

	/**
	 * @param list<string|null> $row Row.
	 * @param array<string,int> $map Map.
	 * @param int               $row_num Line number.
	 * @return array<string,string>|\WP_Error
	 */
	private static function rses_parse_row( array $row, array $map, int $row_num ): array|\WP_Error {
		$get = static function ( string $field ) use ( $row, $map ): string {
			if ( ! isset( $map[ $field ] ) ) {
				return '';
			}
			$i = $map[ $field ];
			return isset( $row[ $i ] ) ? self::rses_normalize_csv_cell( (string) $row[ $i ] ) : '';
		};

		$login    = sanitize_user( $get( 'user_login' ), true );
		$email    = sanitize_email( $get( 'user_email' ) );
		// Passwords are accepted as-is except for spreadsheet whitespace/BOM noise.
		$password = $get( self::PASSWORD_COLUMN );

		if ( '' === $login ) {
			return new \WP_Error( 'rses_login', sprintf( /* translators: %d: row */ __( 'Row %d: user_login is required.', 'relatasoft-secure-election-suite' ), $row_num ) );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'rses_email', sprintf( /* translators: %d: row */ __( 'Row %d: a valid user_email is required.', 'relatasoft-secure-election-suite' ), $row_num ) );
		}
		if ( '' === $password ) {
			return new \WP_Error( 'rses_password', sprintf( /* translators: %d: row */ __( 'Row %d: password is required.', 'relatasoft-secure-election-suite' ), $row_num ) );
		}

		$display = $get( 'display_name' );
		if ( '' === $display ) {
			$display = $login;
		}

		$data = array(
			'user_login'   => $login,
			'user_email'   => $email,
			'display_name' => sanitize_text_field( $display ),
			'first_name'   => sanitize_text_field( $get( 'first_name' ) ),
			'last_name'    => sanitize_text_field( $get( 'last_name' ) ),
			'role'         => self::rses_map_role( $get( 'role' ) ),
			'password'     => $password,
		);

		foreach ( self::META_COLUMNS as $meta_key ) {
			$data[ $meta_key ] = sanitize_text_field( $get( $meta_key ) );
		}

		return $data;
	}

	/**
	 * Map spreadsheet roles onto WP roles for the electoral roll.
	 *
	 * Test sheets often use WooCommerce "customer" → elector (subscriber).
	 */
	public static function rses_map_role( string $role ): string {
		$role = sanitize_key( $role );
		if ( '' === $role || 'customer' === $role || 'elector' === $role || 'eleitor' === $role ) {
			return Capability::RSES_VOTER_ROLE;
		}
		if ( in_array( $role, array( Capability::RSES_VOTER_ROLE, Capability::RSES_OFFICIAL_ROLE ), true ) ) {
			return $role;
		}
		// Never elevate to administrator via electoral roll CSV.
		if ( Capability::RSES_ADMIN_ROLE === $role ) {
			return Capability::RSES_VOTER_ROLE;
		}
		return Capability::RSES_VOTER_ROLE;
	}

	/**
	 * @param array<string,string> $data Parsed row.
	 * @return string|\WP_Error created|updated|skipped
	 */
	private static function rses_upsert_user( array $data, bool $update_existing ): string|\WP_Error {
		$existing = get_user_by( 'login', $data['user_login'] );
		if ( ! $existing ) {
			$by_email = get_user_by( 'email', $data['user_email'] );
			if ( $by_email instanceof \WP_User ) {
				// Same email, different login: never silently rewrite another
				// account's password — Votador would then fail with "invalid credentials".
				return new \WP_Error(
					'rses_email_collision',
					sprintf(
						/* translators: 1: existing login, 2: CSV login, 3: email */
						__( 'Email %3$s already belongs to user “%1$s”, but this row uses user_login “%2$s”. Fix the CSV (unique emails) or rename the existing account.', 'relatasoft-secure-election-suite' ),
						$by_email->user_login,
						$data['user_login'],
						$data['user_email']
					)
				);
			}
		}

		if ( $existing instanceof \WP_User ) {
			if ( ! $update_existing ) {
				return 'skipped';
			}

			$user_id = (int) $existing->ID;
			wp_set_password( $data['password'], $user_id );

			$updated = wp_update_user(
				array(
					'ID'           => $user_id,
					'user_email'   => $data['user_email'],
					'display_name' => $data['display_name'],
					'first_name'   => $data['first_name'],
					'last_name'    => $data['last_name'],
				)
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			$existing->set_role( $data['role'] );
			self::rses_write_meta( $user_id, $data );
			return 'updated';
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $data['user_login'],
				'user_email'   => $data['user_email'],
				'user_pass'    => $data['password'],
				'display_name' => $data['display_name'],
				'first_name'   => $data['first_name'],
				'last_name'    => $data['last_name'],
				'role'         => $data['role'],
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		self::rses_write_meta( (int) $user_id, $data );
		return 'created';
	}

	/**
	 * Persist billing/shipping meta from the spreadsheet.
	 *
	 * @param array<string,string> $data Row data.
	 */
	private static function rses_write_meta( int $user_id, array $data ): void {
		foreach ( self::META_COLUMNS as $meta_key ) {
			if ( ! array_key_exists( $meta_key, $data ) ) {
				continue;
			}
			update_user_meta( $user_id, $meta_key, $data[ $meta_key ] );
		}
		update_user_meta( $user_id, '_rses_electoral_roll', '1' );
	}

	/**
	 * Suppress WP password nagging / notification emails during import.
	 */
	private static function rses_begin_unquestioned_passwords(): void {
		add_filter( 'user_profile_update_errors', array( self::class, 'rses_clear_password_errors' ), 9999, 3 );
		add_filter( 'send_password_change_email', '__return_false', 9999 );
		add_filter( 'send_email_change_email', '__return_false', 9999 );
		add_filter( 'wp_send_new_user_notification_to_user', '__return_false', 9999 );
		add_filter( 'wp_send_new_user_notification_to_admin', '__return_false', 9999 );
		remove_action( 'register_new_user', 'wp_send_new_user_notifications' );
		remove_action( 'edit_user_created_user', 'wp_send_new_user_notifications', 10 );
	}

	/**
	 * Restore notification behaviour.
	 */
	private static function rses_end_unquestioned_passwords(): void {
		remove_filter( 'user_profile_update_errors', array( self::class, 'rses_clear_password_errors' ), 9999 );
		remove_filter( 'send_password_change_email', '__return_false', 9999 );
		remove_filter( 'send_email_change_email', '__return_false', 9999 );
		remove_filter( 'wp_send_new_user_notification_to_user', '__return_false', 9999 );
		remove_filter( 'wp_send_new_user_notification_to_admin', '__return_false', 9999 );
	}

	/**
	 * @param \WP_Error $errors Errors bag.
	 * @param bool      $update Update flag.
	 * @param \stdClass $user   User object.
	 */
	public static function rses_clear_password_errors( \WP_Error $errors, $update, $user ): \WP_Error {
		unset( $update, $user );
		foreach ( array( 'pass', 'pass1', 'pass2', 'empty_password', 'password', 'weak_password', 'incorrect_password' ) as $code ) {
			$errors->remove( $code );
		}
		return $errors;
	}

	/**
	 * @param list<string|null> $row Row.
	 */
	private static function rses_row_empty( array $row ): bool {
		foreach ( $row as $cell ) {
			if ( null !== $cell && '' !== trim( (string) $cell ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalize header labels to spreadsheet keys.
	 */
	private static function rses_normalize_header( string $header ): string {
		$header = self::rses_normalize_csv_cell( $header );
		$header = str_replace( array( '"' ), '', $header );
		$header = strtolower( $header );
		$header = str_replace( array( ' ', '-' ), '_', $header );
		return $header;
	}

	/**
	 * Trim spreadsheet noise (BOM, NBSP, outer whitespace) without altering
	 * intentional inner password characters.
	 */
	public static function rses_normalize_csv_cell( string $value ): string {
		$value = preg_replace( '/^\xEF\xBB\xBF/', '', $value ) ?? $value;
		$value = str_replace( "\xC2\xA0", ' ', $value ); // UTF-8 NBSP → space
		return trim( $value );
	}

	/**
	 * fgetcsv with explicit escape (required on PHP 8.4+).
	 *
	 * @param resource $handle File handle.
	 * @return array<int, string|null>|false
	 */
	private static function rses_fgetcsv( $handle ) {
		return fgetcsv( $handle, 0, ',', '"', self::CSV_ESCAPE );
	}

	/**
	 * fputcsv with explicit escape (required on PHP 8.4+).
	 *
	 * @param resource             $handle File handle.
	 * @param array<int, mixed>    $fields Fields.
	 * @return int|false
	 */
	private static function rses_fputcsv( $handle, array $fields ) {
		return fputcsv( $handle, $fields, ',', '"', self::CSV_ESCAPE );
	}
}
