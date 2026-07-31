<?php
/**
 * CSV elector/user import with plaintext password column.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Imports users from CSV. Last column must be "senha"; accepted as-is.
 */
class UserImportService {

	/**
	 * Canonical header for the password column (must be last).
	 */
	public const PASSWORD_COLUMN = 'senha';

	/**
	 * Maximum rows per upload (excluding header).
	 */
	public const MAX_ROWS = 5000;

	/**
	 * Expected / accepted CSV headers (password always last).
	 *
	 * @return list<string>
	 */
	public static function rses_sample_headers(): array {
		return array( 'login', 'email', 'nome', self::PASSWORD_COLUMN );
	}

	/**
	 * Sample CSV body for download.
	 */
	public static function rses_sample_csv(): string {
		$headers = self::rses_sample_headers();
		$lines   = array(
			implode( ',', $headers ),
			'eleitor01,eleitor01@example.com,Eleitor Exemplo,senha-exemplo-123',
		);
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Import from an uploaded CSV file path.
	 *
	 * @param string $file_path Absolute path to CSV.
	 * @param bool   $update_existing Update password/profile when login or email matches.
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

		$handle = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			$result['errors'][] = __( 'CSV file could not be opened.', 'relatasoft-secure-election-suite' );
			return $result;
		}

		$raw_header = fgetcsv( $handle );
		if ( ! is_array( $raw_header ) || empty( $raw_header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$result['errors'][] = __( 'CSV header row is missing.', 'relatasoft-secure-election-suite' );
			return $result;
		}

		// Strip UTF-8 BOM from first cell.
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
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			++$row_num;

			if ( $row_num - 1 > self::MAX_ROWS ) {
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

		AuditLogger::rses_log(
			'user_csv_import',
			'users',
			null,
			array(
				'command'  => 'import_users_csv',
				'created'  => $result['created'],
				'updated'  => $result['updated'],
				'skipped'  => $result['skipped'],
				'errors'   => count( $result['errors'] ),
			)
		);

		return $result;
	}

	/**
	 * Map header cells to field keys. Password column must be last and named senha.
	 *
	 * @param list<string|null> $headers Header row.
	 * @return array<string,int>|\WP_Error Field => column index.
	 */
	public static function rses_map_headers( array $headers ): array|\WP_Error {
		$normalized = array();
		foreach ( $headers as $i => $cell ) {
			$normalized[ $i ] = self::rses_normalize_header( (string) $cell );
		}

		$last_index = count( $normalized ) - 1;
		while ( $last_index >= 0 && '' === $normalized[ $last_index ] ) {
			--$last_index;
		}
		if ( $last_index < 0 ) {
			return new \WP_Error( 'rses_csv_headers', __( 'CSV header row is empty.', 'relatasoft-secure-election-suite' ) );
		}

		if ( self::PASSWORD_COLUMN !== $normalized[ $last_index ] ) {
			return new \WP_Error(
				'rses_csv_senha',
				sprintf(
					/* translators: %s: required column name */
					__( 'The last CSV column must be named “%s”. Imported passwords are accepted as-is (no WordPress strength checks).', 'relatasoft-secure-election-suite' ),
					self::PASSWORD_COLUMN
				)
			);
		}

		$aliases = array(
			'login'        => array( 'login', 'user_login', 'username', 'identification', 'identificacao', 'identificação' ),
			'email'        => array( 'email', 'user_email', 'e-mail' ),
			'display_name' => array( 'display_name', 'nome', 'name', 'fullname_name' ),
			'first_name'   => array( 'first_name', 'firstname', 'nome_primeiro' ),
			'last_name'    => array( 'last_name', 'lastname', 'sobrenome' ),
			'role'         => array( 'role', 'papel', 'funcao', 'função' ),
			'senha'        => array( self::PASSWORD_COLUMN ),
		);

		$map = array();
		foreach ( $normalized as $index => $header ) {
			if ( '' === $header ) {
				continue;
			}
			foreach ( $aliases as $field => $names ) {
				if ( in_array( $header, $names, true ) ) {
					if ( isset( $map[ $field ] ) ) {
						return new \WP_Error(
							'rses_csv_dup',
							sprintf(
								/* translators: %s: column header */
								__( 'Duplicate CSV column: %s', 'relatasoft-secure-election-suite' ),
								$header
							)
						);
					}
					$map[ $field ] = $index;
					continue 2;
				}
			}
			return new \WP_Error(
				'rses_csv_unknown',
				sprintf(
					/* translators: %s: column header */
					__( 'Unknown CSV column: %s', 'relatasoft-secure-election-suite' ),
					$header
				)
			);
		}

		if ( ! isset( $map['login'] ) || ! isset( $map['email'] ) || ! isset( $map['senha'] ) ) {
			return new \WP_Error(
				'rses_csv_required',
				__( 'CSV must include login, email, and senha (password as the last column).', 'relatasoft-secure-election-suite' )
			);
		}

		if ( $map['senha'] !== $last_index ) {
			return new \WP_Error(
				'rses_csv_senha_order',
				__( 'The senha column must be the last column in the CSV.', 'relatasoft-secure-election-suite' )
			);
		}

		return $map;
	}

	/**
	 * @param list<string|null>   $row Row cells.
	 * @param array<string,int>   $map Header map.
	 * @param int                 $row_num 1-based file line for errors.
	 * @return array{login:string,email:string,senha:string,display_name:string,first_name:string,last_name:string,role:string}|\WP_Error
	 */
	private static function rses_parse_row( array $row, array $map, int $row_num ): array|\WP_Error {
		$get = static function ( string $field ) use ( $row, $map ): string {
			if ( ! isset( $map[ $field ] ) ) {
				return '';
			}
			$i = $map[ $field ];
			return isset( $row[ $i ] ) ? trim( (string) $row[ $i ] ) : '';
		};

		$login = sanitize_user( $get( 'login' ), true );
		$email = sanitize_email( $get( 'email' ) );
		$senha = $get( 'senha' ); // Intentionally not sanitized beyond trim — accept as provided.

		if ( '' === $login ) {
			return new \WP_Error( 'rses_login', sprintf( /* translators: %d: row */ __( 'Row %d: login is required.', 'relatasoft-secure-election-suite' ), $row_num ) );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'rses_email', sprintf( /* translators: %d: row */ __( 'Row %d: a valid email is required.', 'relatasoft-secure-election-suite' ), $row_num ) );
		}
		if ( '' === $senha ) {
			return new \WP_Error( 'rses_senha', sprintf( /* translators: %d: row */ __( 'Row %d: senha is required.', 'relatasoft-secure-election-suite' ), $row_num ) );
		}

		$role = $get( 'role' );
		if ( '' === $role ) {
			$role = Capability::RSES_VOTER_ROLE;
		}
		$role = sanitize_key( $role );

		$display = $get( 'display_name' );
		if ( '' === $display ) {
			$display = $login;
		}

		return array(
			'login'        => $login,
			'email'        => $email,
			'senha'        => $senha,
			'display_name' => sanitize_text_field( $display ),
			'first_name'   => sanitize_text_field( $get( 'first_name' ) ),
			'last_name'    => sanitize_text_field( $get( 'last_name' ) ),
			'role'         => $role,
		);
	}

	/**
	 * Create or update a user. Password is applied without strength checks.
	 *
	 * @param array{login:string,email:string,senha:string,display_name:string,first_name:string,last_name:string,role:string} $data Row.
	 * @return string|\WP_Error created|updated|skipped
	 */
	private static function rses_upsert_user( array $data, bool $update_existing ): string|\WP_Error {
		$existing = get_user_by( 'login', $data['login'] );
		if ( ! $existing ) {
			$by_email = get_user_by( 'email', $data['email'] );
			if ( $by_email ) {
				$existing = $by_email;
			}
		}

		if ( $existing instanceof \WP_User ) {
			if ( ! $update_existing ) {
				return 'skipped';
			}

			$user_id = (int) $existing->ID;

			// Apply password as-is (no WP strength UI / confirmation).
			wp_set_password( $data['senha'], $user_id );

			$update = array(
				'ID'           => $user_id,
				'user_email'   => $data['email'],
				'display_name' => $data['display_name'],
			);
			if ( '' !== $data['first_name'] ) {
				$update['first_name'] = $data['first_name'];
			}
			if ( '' !== $data['last_name'] ) {
				$update['last_name'] = $data['last_name'];
			}

			$updated = wp_update_user( $update );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			$existing->set_role( $data['role'] );
			return 'updated';
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $data['login'],
				'user_email'   => $data['email'],
				'user_pass'    => $data['senha'],
				'display_name' => $data['display_name'],
				'first_name'   => $data['first_name'],
				'last_name'    => $data['last_name'],
				'role'         => $data['role'],
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		return 'created';
	}

	/**
	 * Disable WP password nagging / notifications for the import window.
	 */
	private static function rses_begin_unquestioned_passwords(): void {
		add_filter( 'wp_is_password_strong', array( self::class, 'rses_force_password_strong' ), 9999 );
		add_filter( 'user_profile_update_errors', array( self::class, 'rses_clear_password_errors' ), 9999, 3 );
		add_filter( 'send_password_change_email', '__return_false', 9999 );
		add_filter( 'send_email_change_email', '__return_false', 9999 );
		add_filter( 'wp_send_new_user_notification_to_user', '__return_false', 9999 );
		add_filter( 'wp_send_new_user_notification_to_admin', '__return_false', 9999 );
		remove_action( 'register_new_user', 'wp_send_new_user_notifications' );
		remove_action( 'edit_user_created_user', 'wp_send_new_user_notifications', 10 );
	}

	/**
	 * Restore default behaviour after import.
	 */
	private static function rses_end_unquestioned_passwords(): void {
		remove_filter( 'wp_is_password_strong', array( self::class, 'rses_force_password_strong' ), 9999 );
		remove_filter( 'user_profile_update_errors', array( self::class, 'rses_clear_password_errors' ), 9999 );
		remove_filter( 'send_password_change_email', '__return_false', 9999 );
		remove_filter( 'send_email_change_email', '__return_false', 9999 );
		remove_filter( 'wp_send_new_user_notification_to_user', '__return_false', 9999 );
		remove_filter( 'wp_send_new_user_notification_to_admin', '__return_false', 9999 );
	}

	/**
	 * Treat any imported password as acceptable to strength checkers.
	 *
	 * @param bool   $is_strong Current.
	 * @param string $password  Password.
	 */
	public static function rses_force_password_strong( $is_strong, $password = '' ): bool {
		unset( $is_strong, $password );
		return true;
	}

	/**
	 * Drop password-related profile errors raised by core/plugins during import.
	 *
	 * @param \WP_Error $errors Errors.
	 * @param bool      $update Update flag.
	 * @param \stdClass $user   User.
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
	 * Normalize a header label.
	 */
	private static function rses_normalize_header( string $header ): string {
		$header = trim( $header );
		$header = str_replace( array( "\xEF\xBB\xBF", '"' ), '', $header );
		$header = strtolower( $header );
		$header = str_replace( array( ' ', '-', '.' ), '_', $header );
		return $header;
	}
}
