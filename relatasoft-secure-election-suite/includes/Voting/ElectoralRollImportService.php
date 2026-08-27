<?php
/**
 * Electoral roll (cadastro eleitoral) .rsv import.
 *
 * Formato RelataSoft Separated Values: `:` campos, `;` séries, `,` só em texto livre.
 * Domínio puro: {@see \RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll\RsvFormat}.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll\RsvFormat;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Imports electors from the electoral registration .rsv file.
 */
class ElectoralRollImportService {

	/**
	 * Soft cap per upload (~500k rows).
	 */
	public const MAX_ROWS = 500000;

	/**
	 * User-meta keys written for each imported row.
	 */
	public const META_ID_CIVIL         = '_rses_id_civil';
	public const META_ID_ELEITORAL     = '_rses_id_eleitoral';
	public const META_REGIAO_AMPLA     = '_rses_regiao_ampla';
	public const META_REGIAO_ESPECIFICA = '_rses_regiao_especifica';
	public const META_CELULAR          = '_rses_celular';
	public const META_EMAILS           = '_rses_emails';
	public const META_ENDERECO         = '_rses_endereco';
	public const META_ELECTORAL_ROLL   = '_rses_electoral_roll';

	/**
	 * Expected RSV header keys (same order as RsvFormat::HEADERS).
	 *
	 * Kept for Admin page compatibility until the UI is updated for .rsv.
	 *
	 * @return list<string>
	 */
	public static function rses_expected_headers(): array {
		return RsvFormat::HEADERS;
	}

	/**
	 * Localized download basename for the example RSV (e.g. exemplo.rsv).
	 */
	public static function rses_sample_filename(): string {
		$locale = \RelataSoft\SecureElectionSuite\I18n\LocaleResolver::rses_resolve();
		$stem   = self::rses_example_stem_for_locale( $locale );
		return $stem . '.' . RsvFormat::EXTENSION;
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
	 * Sample RSV content: header + 10 data rows (method name kept for Page/JS).
	 *
	 * Roles use PT-BR canonical labels (`eleitor`). Emails/phones use `;` series;
	 * addresses may contain commas (never a field separator).
	 */
	public static function rses_sample_csv(): string {
		$lines   = array( RsvFormat::headerLine() );
		$cities  = array(
			array( 'Brasília', 'DF' ),
			array( 'Maceió', 'AL' ),
			array( 'Manaus', 'AM' ),
			array( 'Salvador', 'BA' ),
			array( 'Fortaleza', 'CE' ),
			array( 'Vitória', 'ES' ),
			array( 'Goiânia', 'GO' ),
			array( 'São Luís', 'MA' ),
			array( 'Belo Horizonte', 'MG' ),
			array( 'Belém', 'PA' ),
		);

		for ( $i = 1; $i <= 10; $i++ ) {
			$n     = sprintf( '%04d', $i );
			$city  = $cities[ $i - 1 ];
			$login = 'eleitor.exemplo.' . $n;
			$civil = '100000000' . sprintf( '%02d', $i );
			$eleit = '20000000000' . sprintf( '%02d', $i );
			$nome  = 'Eleitor Exemplo ' . $n;
			$cel   = RsvFormat::joinSeries(
				array(
					'+55119' . str_pad( (string) ( 10000000 + $i ), 8, '0', STR_PAD_LEFT ),
					'+55118' . str_pad( (string) ( 20000000 + $i ), 8, '0', STR_PAD_LEFT ),
				)
			);
			$email = RsvFormat::joinSeries(
				array(
					$n . '@relatasoft.com.br',
					'alt.' . $n . '@exemplo.relatasoft.com.br',
				)
			);
			// Vírgulas só no endereço (texto livre), nunca como separador de campo.
			$end = sprintf(
				'Rua Exemplo %d, %s, %s-%s, CEP %s',
				$i,
				( 0 === $i % 2 ) ? ( 'Apto ' . $i ) : ( 'Casa ' . $i ),
				$city[0],
				$city[1],
				str_pad( (string) ( 70000000 + $i ), 8, '0', STR_PAD_LEFT )
			);

			$lines[] = RsvFormat::serializeLine(
				array(
					$login,
					$civil,
					$eleit,
					'Zona ' . ( 10 + $i ),
					'Seção ' . sprintf( '%04d', $i ),
					$nome,
					$cel,
					$email,
					$end,
					'eleitor',
					'senha-exemplo-' . $n,
				)
			);
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Build a downloadable error report (RSV-style: linha:mensagem).
	 *
	 * Method name kept for Page compatibility; content uses `:` field sep.
	 *
	 * @param list<string> $errors Error messages.
	 */
	public static function rses_errors_csv( array $errors ): string {
		$lines = array( 'linha' . RsvFormat::FIELD_SEP . 'erro' );

		foreach ( $errors as $message ) {
			$message = (string) $message;
			$line    = '';
			if ( preg_match( '/(?:Row|Linha)\s+(\d+)/u', $message, $m ) ) {
				$line = $m[1];
			}
			// Rest of the line after first `:` is the free-text message (may contain `:`).
			$lines[] = $line . RsvFormat::FIELD_SEP . $message;
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Import an uploaded RSV path in one pass (sync / admin_post fallback).
	 *
	 * @param string $file_path       Absolute path.
	 * @param bool   $update_existing Update matching login / civil / electoral ID.
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
			$result['errors'][] = __( 'RSV file could not be read.', 'relatasoft-secure-election-suite' );
			return $result;
		}

		// Large rolls can exceed the default PHP max_execution_time.
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- intentional for bulk import
			@set_time_limit( 0 );
		}

		$prep = self::rses_prepare_file( $file_path );
		if ( is_wp_error( $prep ) ) {
			$result['errors'][] = $prep->get_error_message();
			return $result;
		}

		// Password filters are applied inside each rses_process_batch() call.
		$offset  = (int) $prep['byte_offset'];
		$row_num = 1;
		$done    = false;

		while ( ! $done ) {
			$batch = self::rses_process_batch(
				$file_path,
				(array) $prep['map'],
				$offset,
				$row_num,
				500,
				$update_existing,
				self::MAX_ROWS
			);
			if ( is_wp_error( $batch ) ) {
				$result['errors'][] = $batch->get_error_message();
				break;
			}

			$result['created'] += (int) $batch['created'];
			$result['updated'] += (int) $batch['updated'];
			$result['skipped'] += (int) $batch['skipped'];
			foreach ( $batch['errors'] as $err ) {
				$result['errors'][] = (string) $err;
			}

			$offset  = (int) $batch['byte_offset'];
			$row_num = (int) $batch['next_row_num'];
			$done    = ! empty( $batch['done'] );
		}

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
				'command' => 'import_electoral_roll_rsv',
				'created' => $created,
				'updated' => $updated,
				'skipped' => $skipped,
				'errors'  => $errors,
			)
		);
	}

	/**
	 * Validate RSV headers, count non-empty data lines, return byte offset after header.
	 *
	 * @return array{map:array<string,int>,byte_offset:int,total_rows:int,format:string}|\WP_Error
	 */
	public static function rses_prepare_file( string $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return new \WP_Error( 'rses_rsv_read', __( 'RSV file could not be read.', 'relatasoft-secure-election-suite' ) );
		}

		$handle = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new \WP_Error( 'rses_rsv_open', __( 'RSV file could not be opened.', 'relatasoft-secure-election-suite' ) );
		}

		$header_line = fgets( $handle );
		if ( false === $header_line || '' === trim( $header_line ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'rses_rsv_headers', __( 'RSV header row is missing.', 'relatasoft-secure-election-suite' ) );
		}

		$header_fields = RsvFormat::parseLine( $header_line );
		if ( null === $header_fields ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error(
				'rses_rsv_headers',
				__( 'RSV header row is invalid (wrong field count). Expected login:…:senha.', 'relatasoft-secure-election-suite' )
			);
		}

		$map = array();
		foreach ( RsvFormat::HEADERS as $i => $expected ) {
			$got = strtolower( trim( (string) $header_fields[ $i ] ) );
			if ( $got !== $expected ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new \WP_Error(
					'rses_rsv_headers',
					sprintf(
						/* translators: 1: expected header key, 2: found header key */
						__( 'RSV header mismatch at column %1$s (found “%2$s”).', 'relatasoft-secure-election-suite' ),
						$expected,
						$got
					)
				);
			}
			$map[ $expected ] = $i;
		}

		$byte_offset = ftell( $handle );
		if ( false === $byte_offset ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'rses_rsv_seek', __( 'Could not determine RSV data offset.', 'relatasoft-secure-election-suite' ) );
		}

		$total_rows = 0;
		while ( ( $line = fgets( $handle ) ) !== false ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed ) {
				continue;
			}
			++$total_rows;
			if ( $total_rows > self::MAX_ROWS ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new \WP_Error(
					'rses_rsv_max',
					sprintf(
						/* translators: 1: found rows (at least), 2: max rows */
						__( 'Import stopped: the RSV has more than %1$d data rows (limit is %2$d). Split the file and try again.', 'relatasoft-secure-election-suite' ),
						self::MAX_ROWS,
						self::MAX_ROWS
					)
				);
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $total_rows < 1 ) {
			return new \WP_Error( 'rses_rsv_empty', __( 'RSV has a header but no data rows.', 'relatasoft-secure-election-suite' ) );
		}

		return array(
			'map'         => $map,
			'byte_offset' => (int) $byte_offset,
			'total_rows'  => $total_rows,
			'format'      => 'rsv',
		);
	}

	/**
	 * Process up to $limit non-empty data lines from a byte offset (fgets + RsvFormat).
	 *
	 * Empty lines are skipped without counting as processed. `$map` is accepted for
	 * Job compatibility but unused (RSV columns are fixed by RsvFormat::HEADERS).
	 *
	 * @param array<string,int> $map Header map (ignored for RSV).
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
		unset( $map ); // Fixed RSV schema — Job still passes map for backward compat.

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
			return new \WP_Error( 'rses_rsv_read', __( 'RSV file could not be read.', 'relatasoft-secure-election-suite' ) );
		}

		$handle = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new \WP_Error( 'rses_rsv_open', __( 'RSV file could not be opened.', 'relatasoft-secure-election-suite' ) );
		}

		if ( $byte_offset > 0 && 0 !== fseek( $handle, $byte_offset ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'rses_rsv_seek', __( 'Could not seek in RSV file.', 'relatasoft-secure-election-suite' ) );
		}

		$limit     = max( 1, $limit );
		$max_reads = max( $limit * 4, $limit + 50 );
		$reads     = 0;
		self::rses_begin_unquestioned_passwords();

		while ( $result['processed'] < $limit && $reads < $max_reads ) {
			$line = fgets( $handle );
			++$reads;
			if ( false === $line ) {
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

			if ( '' === trim( $line ) ) {
				// Empty line: advance offset/row counter but do not count as processed.
				continue;
			}

			++$result['processed'];

			$parsed = self::rses_parse_rsv_line( $line, $result['next_row_num'] );
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
	 * Parse one RSV data line into upsert payload.
	 *
	 * @return array<string,string>|\WP_Error
	 */
	private static function rses_parse_rsv_line( string $line, int $row_num ): array|\WP_Error {
		$fields = RsvFormat::parseLine( $line );
		if ( null === $fields ) {
			return new \WP_Error(
				'rses_rsv_fields',
				sprintf(
					/* translators: %d: row */
					__( 'Row %d: invalid RSV field count (expected login:…:senha).', 'relatasoft-secure-election-suite' ),
					$row_num
				)
			);
		}

		$assoc = RsvFormat::associate( $fields );

		$login = sanitize_user( self::rses_normalize_cell( $assoc['login'] ), true );
		if ( '' === $login ) {
			return new \WP_Error(
				'rses_login',
				sprintf(
					/* translators: %d: row */
					__( 'Row %d: login is required.', 'relatasoft-secure-election-suite' ),
					$row_num
				)
			);
		}

		$email_series = self::rses_normalize_cell( $assoc['email'] );
		$emails       = RsvFormat::splitSeries( $email_series );
		$primary      = $emails[0] ?? '';
		$primary      = sanitize_email( $primary );
		if ( '' === $primary || ! is_email( $primary ) ) {
			return new \WP_Error(
				'rses_email',
				sprintf(
					/* translators: %d: row */
					__( 'Row %d: a valid primary email is required (first item in the email series).', 'relatasoft-secure-election-suite' ),
					$row_num
				)
			);
		}

		// Passwords accepted as-is except outer spreadsheet whitespace/BOM noise.
		// Empty senha is allowed on UPDATE (keep existing hash); CREATE still requires it.
		$password = self::rses_normalize_cell( $assoc['senha'] );

		$papel = self::rses_normalize_cell( $assoc['papel'] );
		$role  = RsvFormat::mapRole( $papel );
		if ( null === $role ) {
			return new \WP_Error(
				'rses_papel',
				sprintf(
					/* translators: 1: row number, 2: role label */
					__( 'Row %1$d: unknown role (papel) “%2$s”.', 'relatasoft-secure-election-suite' ),
					$row_num,
					$papel
				)
			);
		}

		$nome = sanitize_text_field( self::rses_normalize_cell( $assoc['nomecompleto'] ) );
		if ( '' === $nome ) {
			$nome = $login;
		}
		[ $first_name, $last_name ] = self::rses_split_nome( $nome );

		$celular_series = self::rses_normalize_cell( $assoc['celular'] );

		return array(
			'user_login'        => $login,
			'user_email'        => $primary,
			'display_name'      => $nome,
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'role'              => $role,
			'password'          => $password,
			'id_civil'          => sanitize_text_field( self::rses_normalize_cell( $assoc['numerodeidentificacaocivil'] ) ),
			'id_eleitoral'      => sanitize_text_field( self::rses_normalize_cell( $assoc['numerodeidentificacaoeleitoral'] ) ),
			'regiao_ampla'      => sanitize_text_field( self::rses_normalize_cell( $assoc['regiaoeleitoralampla'] ) ),
			'regiao_especifica' => sanitize_text_field( self::rses_normalize_cell( $assoc['regiaoeleitoralespecifica'] ) ),
			'celular'           => $celular_series,
			'emails'            => $email_series,
			'endereco'          => sanitize_text_field( self::rses_normalize_cell( $assoc['endereco'] ) ),
		);
	}

	/**
	 * Heuristic first/last name from nomecompleto (first token / remainder).
	 *
	 * @return array{0:string,1:string}
	 */
	private static function rses_split_nome( string $nome ): array {
		$nome = trim( $nome );
		if ( '' === $nome ) {
			return array( '', '' );
		}
		$parts = preg_split( '/\s+/u', $nome, 2 );
		if ( ! is_array( $parts ) || ! isset( $parts[0] ) ) {
			return array( $nome, '' );
		}
		return array(
			sanitize_text_field( $parts[0] ),
			sanitize_text_field( $parts[1] ?? '' ),
		);
	}

	/**
	 * Resolve existing WP user: login → civil ID meta → electoral ID meta.
	 *
	 * @param array<string,string> $data Parsed row.
	 */
	private static function rses_find_existing_user( array $data ): ?\WP_User {
		$by_login = get_user_by( 'login', $data['user_login'] );
		if ( $by_login instanceof \WP_User ) {
			return $by_login;
		}

		if ( '' !== $data['id_civil'] ) {
			$uid = self::rses_user_id_by_meta( self::META_ID_CIVIL, $data['id_civil'] );
			if ( $uid > 0 ) {
				$user = get_user_by( 'id', $uid );
				if ( $user instanceof \WP_User ) {
					return $user;
				}
			}
		}

		if ( '' !== $data['id_eleitoral'] ) {
			$uid = self::rses_user_id_by_meta( self::META_ID_ELEITORAL, $data['id_eleitoral'] );
			if ( $uid > 0 ) {
				$user = get_user_by( 'id', $uid );
				if ( $user instanceof \WP_User ) {
					return $user;
				}
			}
		}

		return null;
	}

	/**
	 * Look up a single user ID by exact meta value (civil / electoral ID conflict → update).
	 */
	private static function rses_user_id_by_meta( string $meta_key, string $meta_value ): int {
		// get_users(): WP helper — returns matching users; we only need the first ID.
		$users = get_users(
			array(
				'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
				'count_total'=> false,
			)
		);
		if ( empty( $users ) ) {
			return 0;
		}
		return (int) ( is_array( $users ) ? ( $users[0] ?? 0 ) : $users );
	}

	/**
	 * @param array<string,string> $data Parsed row.
	 * @return string|\WP_Error created|updated|skipped
	 */
	private static function rses_upsert_user( array $data, bool $update_existing ): string|\WP_Error {
		$existing = self::rses_find_existing_user( $data );

		// Email collision with a *different* login/account must not silently rewrite passwords.
		$by_email = get_user_by( 'email', $data['user_email'] );
		if ( $by_email instanceof \WP_User ) {
			$same = $existing instanceof \WP_User && (int) $by_email->ID === (int) $existing->ID;
			if ( ! $same ) {
				return new \WP_Error(
					'rses_email_collision',
					sprintf(
						/* translators: 1: existing login, 2: RSV login, 3: email */
						__( 'Email %3$s already belongs to user “%1$s”, but this row uses login “%2$s”. Fix the RSV (unique emails) or rename the existing account.', 'relatasoft-secure-election-suite' ),
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
			// Empty senha on UPDATE → keep existing password (hashes are not recoverable).
			if ( '' !== $data['password'] ) {
				// wp_set_password(): hashes and stores; bypasses strength UI (filters suppress nags).
				wp_set_password( $data['password'], $user_id );
			}

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

		if ( '' === $data['password'] ) {
			return new \WP_Error(
				'rses_password',
				__( 'Password (senha) is required when creating a new user.', 'relatasoft-secure-election-suite' )
			);
		}

		// wp_insert_user(): creates WP user; role from RsvFormat (admin/gestor/auditor allowed).
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
	 * Persist electoral-roll meta keys.
	 *
	 * @param array<string,string> $data Row data.
	 */
	private static function rses_write_meta( int $user_id, array $data ): void {
		update_user_meta( $user_id, self::META_ID_CIVIL, $data['id_civil'] );
		update_user_meta( $user_id, self::META_ID_ELEITORAL, $data['id_eleitoral'] );
		update_user_meta( $user_id, self::META_REGIAO_AMPLA, $data['regiao_ampla'] );
		update_user_meta( $user_id, self::META_REGIAO_ESPECIFICA, $data['regiao_especifica'] );
		update_user_meta( $user_id, self::META_CELULAR, $data['celular'] );
		update_user_meta( $user_id, self::META_EMAILS, $data['emails'] );
		update_user_meta( $user_id, self::META_ENDERECO, $data['endereco'] );
		update_user_meta( $user_id, self::META_ELECTORAL_ROLL, '1' );
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
	 * Trim BOM / NBSP / outer whitespace without altering intentional inner password chars.
	 */
	public static function rses_normalize_cell( string $value ): string {
		$value = preg_replace( '/^\xEF\xBB\xBF/', '', $value ) ?? $value;
		$value = str_replace( "\xC2\xA0", ' ', $value ); // UTF-8 NBSP → space
		return trim( $value );
	}
}
