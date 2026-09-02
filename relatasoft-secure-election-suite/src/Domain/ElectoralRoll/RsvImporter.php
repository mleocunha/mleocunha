<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll;

use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserDirectory;

/**
 * Importação .rsv → UserDirectory (domínio puro, sem I/O HTTP).
 *
 * Contrato de campos/meta alinhado ao Cadastro Eleitoral do produto.
 */
final class RsvImporter {

	public const META_ID_CIVIL          = '_rses_id_civil';
	public const META_ID_ELEITORAL      = '_rses_id_eleitoral';
	public const META_REGIAO_AMPLA      = '_rses_regiao_ampla';
	public const META_REGIAO_ESPECIFICA = '_rses_regiao_especifica';
	public const META_CELULAR           = '_rses_celular';
	public const META_EMAILS            = '_rses_emails';
	public const META_ENDERECO          = '_rses_endereco';
	public const META_ELECTORAL_ROLL    = '_rses_electoral_roll';

	/**
	 * @return array{created:int,updated:int,skipped:int,errors:list<array{line:int,message:string}>}
	 */
	public static function importText( string $text, UserDirectory $dir, bool $updateExisting = true ): array {
		$text  = self::stripBom( $text );
		$lines = preg_split( "/\n/", $text ) ?: array();
		$created = 0;
		$updated = 0;
		$skipped = 0;
		$errors  = array();
		$sawHeader = false;

		foreach ( $lines as $i => $raw ) {
			$lineNo = $i + 1;
			$raw    = rtrim( (string) $raw, "\r" );
			if ( '' === trim( $raw ) ) {
				continue;
			}
			$fields = RsvFormat::parseLine( $raw );
			if ( null === $fields ) {
				$errors[] = array( 'line' => $lineNo, 'message' => 'Campo count inválido (esperado ' . count( RsvFormat::HEADERS ) . ').' );
				continue;
			}
			$assoc = RsvFormat::associate( $fields );
			if ( ! $sawHeader ) {
				$okHeader = true;
				foreach ( RsvFormat::HEADERS as $hi => $name ) {
					if ( strtolower( $assoc[ $name ] ?? '' ) !== $name && strtolower( $fields[ $hi ] ?? '' ) !== $name ) {
						// First non-empty line must be header OR data; detect header by exact names.
					}
				}
				$headerMatch = true;
				foreach ( RsvFormat::HEADERS as $hi => $name ) {
					if ( strtolower( trim( $fields[ $hi ] ) ) !== $name ) {
						$headerMatch = false;
						break;
					}
				}
				if ( $headerMatch ) {
					$sawHeader = true;
					continue;
				}
				$errors[] = array( 'line' => $lineNo, 'message' => 'Cabeçalho RSV canónico em falta.' );
				break;
			}

			$row = self::normalizeRow( $assoc );
			if ( null === $row ) {
				$errors[] = array( 'line' => $lineNo, 'message' => 'Login ou papel inválido.' );
				continue;
			}

			$result = self::upsert( $dir, $row, $updateExisting );
			if ( 'created' === $result ) {
				++$created;
			} elseif ( 'updated' === $result ) {
				++$updated;
			} elseif ( 'skipped' === $result ) {
				++$skipped;
			} else {
				$errors[] = array( 'line' => $lineNo, 'message' => $result );
			}
		}

		if ( ! $sawHeader ) {
			$errors[] = array( 'line' => 0, 'message' => 'Arquivo RSV sem cabeçalho.' );
		}

		return compact( 'created', 'updated', 'skipped', 'errors' );
	}

	/**
	 * @param array<string,string> $assoc
	 * @return array<string,string>|null
	 */
	private static function normalizeRow( array $assoc ): ?array {
		$login = trim( $assoc['login'] ?? '' );
		$papel = strtolower( trim( $assoc['papel'] ?? '' ) );
		$role  = RsvFormat::mapRole( $papel );
		if ( '' === $login || null === $role ) {
			return null;
		}
		$emails = RsvFormat::splitSeries( $assoc['email'] ?? '' );
		$email  = $emails[0] ?? '';
		if ( '' === $email ) {
			$email = $login . '@local.invalid';
		}
		$nome = trim( $assoc['nomecompleto'] ?? '' );
		$parts = preg_split( '/\s+/u', $nome, 2 ) ?: array( $nome, '' );
		return array(
			'user_login'        => $login,
			'user_email'        => $email,
			'display_name'      => '' !== $nome ? $nome : $login,
			'first_name'        => (string) ( $parts[0] ?? '' ),
			'last_name'         => (string) ( $parts[1] ?? '' ),
			'role'              => $role,
			'password'          => (string) ( $assoc['senha'] ?? '' ),
			'id_civil'          => trim( $assoc['numerodeidentificacaocivil'] ?? '' ),
			'id_eleitoral'      => trim( $assoc['numerodeidentificacaoeleitoral'] ?? '' ),
			'regiao_ampla'      => trim( $assoc['regiaoeleitoralampla'] ?? '' ),
			'regiao_especifica' => trim( $assoc['regiaoeleitoralespecifica'] ?? '' ),
			'celular'           => trim( $assoc['celular'] ?? '' ),
			'emails'            => trim( $assoc['email'] ?? '' ),
			'endereco'          => trim( $assoc['endereco'] ?? '' ),
		);
	}

	/**
	 * @param array<string,string> $data
	 * @return 'created'|'updated'|'skipped'|string error message
	 */
	private static function upsert( UserDirectory $dir, array $data, bool $updateExisting ): string {
		$existing = $dir->findByLogin( $data['user_login'] );
		if ( null === $existing && '' !== $data['id_civil'] ) {
			$uid = $dir->findIdByMeta( self::META_ID_CIVIL, $data['id_civil'] );
			$existing = $uid > 0 ? $dir->findById( $uid ) : null;
		}
		if ( null === $existing && '' !== $data['id_eleitoral'] ) {
			$uid = $dir->findIdByMeta( self::META_ID_ELEITORAL, $data['id_eleitoral'] );
			$existing = $uid > 0 ? $dir->findById( $uid ) : null;
		}

		$byEmail = $dir->findByEmail( $data['user_email'] );
		if ( null !== $byEmail ) {
			$same = null !== $existing && (int) $byEmail['id'] === (int) $existing['id'];
			if ( ! $same ) {
				return 'Email já pertence a outro login (' . $byEmail['login'] . ').';
			}
		}

		if ( null !== $existing ) {
			if ( ! $updateExisting ) {
				return 'skipped';
			}
			$id = (int) $existing['id'];
			if ( '' !== $data['password'] ) {
				$dir->setPassword( $id, $data['password'] );
			}
			$upd = $dir->update(
				$id,
				array(
					'email'       => $data['user_email'],
					'displayName' => $data['display_name'],
					'firstName'   => $data['first_name'],
					'lastName'    => $data['last_name'],
					'role'        => $data['role'],
				)
			);
			if ( ! $upd['ok'] ) {
				return (string) ( $upd['error'] ?? 'falha ao atualizar' );
			}
			self::writeMeta( $dir, $id, $data );
			return 'updated';
		}

		if ( '' === $data['password'] ) {
			return 'Senha obrigatória na criação.';
		}
		$created = $dir->create(
			array(
				'login'       => $data['user_login'],
				'email'       => $data['user_email'],
				'password'    => $data['password'],
				'displayName' => $data['display_name'],
				'firstName'   => $data['first_name'],
				'lastName'    => $data['last_name'],
				'role'        => $data['role'],
			)
		);
		if ( ! $created['ok'] ) {
			return (string) ( $created['error'] ?? 'falha ao criar' );
		}
		self::writeMeta( $dir, (int) $created['id'], $data );
		return 'created';
	}

	/** @param array<string,string> $data */
	private static function writeMeta( UserDirectory $dir, int $userId, array $data ): void {
		$dir->setMeta( $userId, self::META_ID_CIVIL, $data['id_civil'] );
		$dir->setMeta( $userId, self::META_ID_ELEITORAL, $data['id_eleitoral'] );
		$dir->setMeta( $userId, self::META_REGIAO_AMPLA, $data['regiao_ampla'] );
		$dir->setMeta( $userId, self::META_REGIAO_ESPECIFICA, $data['regiao_especifica'] );
		$dir->setMeta( $userId, self::META_CELULAR, $data['celular'] );
		$dir->setMeta( $userId, self::META_EMAILS, $data['emails'] );
		$dir->setMeta( $userId, self::META_ENDERECO, $data['endereco'] );
		$dir->setMeta( $userId, self::META_ELECTORAL_ROLL, '1' );
	}

	private static function stripBom( string $s ): string {
		return str_starts_with( $s, "\xEF\xBB\xBF" ) ? substr( $s, 3 ) : $s;
	}
}
