<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Authorities;

use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserDirectory;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\UserRegistryRoles;

/**
 * Exportar / importar autoridades eleitorais entre nós (formato AuthoritiesPackage).
 *
 * Transporte: descarregar na sessão (admin ou autoridade) e carregar no outro nó.
 * Nunca inclui share_value — só metadados públicos SSS (parcela pública).
 */
final class AuthoritiesDirectorySync {

	public const PACKAGE_FILENAME = 'authorities.json';

	/** @deprecated Use PACKAGE_FILENAME — Courier abandonado. */
	public const COURIER_FILE = self::PACKAGE_FILENAME;

	/**
	 * @param list<array<string,mixed>> $officials Users do papel autoridade (já normalizados).
	 * @param array<int,array<string,mixed>> $shareMeta Por official_user_id: índice, limiar, chave pública, etc.
	 * @return array<string,mixed>
	 */
	public static function buildPackage(
		array $officials,
		array $shareMeta = array(),
		string $sourceMode = '',
		string $sourceSite = ''
	): array {
		$rows = array();
		foreach ( $officials as $user ) {
			$uid = (int) ( $user['id'] ?? 0 );
			$row = array(
				'user_login'     => (string) ( $user['login'] ?? '' ),
				'user_email'     => (string) ( $user['email'] ?? '' ),
				'display_name'   => (string) ( $user['displayName'] ?? '' ),
				'first_name'     => (string) ( $user['firstName'] ?? '' ),
				'last_name'      => (string) ( $user['lastName'] ?? '' ),
				'role'           => UserRegistryRoles::ROLE_OFFICIAL,
				'user_pass'      => (string) ( $user['passwordHash'] ?? '' ),
				'source_user_id' => $uid,
			);
			if ( isset( $shareMeta[ $uid ] ) ) {
				$public = self::publicSssFromMeta( $shareMeta[ $uid ] );
				if ( null !== $public ) {
					$row['share_index']   = $public['share_index'];
					$row['source_key_id'] = $public['source_key_id'];
					$row['threshold_t']   = $public['threshold_t'];
					$row['total_n']       = $public['total_n'];
					$row['public_sss']    = $public;
				}
			}
			$rows[] = $row;
		}
		return AuthoritiesPackage::build(
			array(
				'exported_at'    => gmdate( 'c' ),
				'source_site'    => $sourceSite,
				'source_mode'    => $sourceMode,
				'plugin_version' => '',
				'authorities'    => $rows,
			)
		);
	}

	/**
	 * Extrair só a parcela pública SSS (sem share_value) a partir de meta/persistência.
	 *
	 * @param array<string,mixed> $meta
	 * @return array{
	 *   share_index:int,
	 *   source_key_id:int,
	 *   threshold_t:int,
	 *   total_n:int,
	 *   field_prime:string,
	 *   key_label:string,
	 *   key_size:int,
	 *   public_key:array{p:string,q:string,g:string,y:string}
	 * }|null
	 */
	public static function publicSssFromMeta( array $meta ): ?array {
		$idx = (int) ( $meta['share_index'] ?? 0 );
		if ( $idx < 1 ) {
			return null;
		}
		$payload = is_array( $meta['share_payload'] ?? null ) ? $meta['share_payload'] : array();
		$pk      = $meta['public_key'] ?? ( $payload['public_key'] ?? null );
		if ( ! is_array( $pk ) ) {
			$pk = array(
				'p' => (string) ( $meta['public_p'] ?? '' ),
				'q' => (string) ( $meta['public_q'] ?? '' ),
				'g' => (string) ( $meta['public_g'] ?? '' ),
				'y' => (string) ( $meta['public_y'] ?? '' ),
			);
		}
		foreach ( array( 'p', 'q', 'g', 'y' ) as $k ) {
			if ( '' === trim( (string) ( $pk[ $k ] ?? '' ) ) ) {
				return null;
			}
		}
		return array(
			'share_index'   => $idx,
			'source_key_id' => (int) ( $meta['key_id'] ?? $meta['source_key_id'] ?? 0 ),
			'threshold_t'   => (int) ( $meta['threshold_t'] ?? 0 ),
			'total_n'       => (int) ( $meta['total_n'] ?? 0 ),
			'field_prime'   => (string) ( $meta['field_prime'] ?? $payload['field_prime'] ?? '' ),
			'key_label'     => (string) ( $meta['key_label'] ?? '' ),
			'key_size'      => (int) ( $meta['key_size'] ?? 0 ),
			'public_key'    => array(
				'p' => (string) $pk['p'],
				'q' => (string) $pk['q'],
				'g' => (string) $pk['g'],
				'y' => (string) $pk['y'],
			),
		);
	}

	/**
	 * @param array<string,mixed> $package
	 * @return array{created:int,updated:int,skipped:int,errors:list<string>}
	 */
	public static function importPackage( UserDirectory $dir, array $package ): array {
		$result = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);
		$v = AuthoritiesPackage::validate( $package );
		if ( empty( $v['ok'] ) ) {
			$result['errors'][] = 'Pacote inválido: ' . (string) ( $v['error'] ?? 'erro' );
			return $result;
		}
		foreach ( $package['authorities'] as $i => $row ) {
			if ( ! is_array( $row ) ) {
				++$result['skipped'];
				continue;
			}
			$outcome = self::upsert( $dir, $row );
			if ( isset( $outcome['error'] ) ) {
				$result['errors'][] = 'Autoridade #' . ( (int) $i + 1 ) . ': ' . $outcome['error'];
				++$result['skipped'];
				continue;
			}
			$status = (string) ( $outcome['status'] ?? 'skipped' );
			if ( 'created' === $status ) {
				++$result['created'];
			} elseif ( 'updated' === $status ) {
				++$result['updated'];
			} else {
				++$result['skipped'];
			}
		}
		return $result;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array{status?:string,error?:string}
	 */
	private static function upsert( UserDirectory $dir, array $row ): array {
		$login = trim( (string) ( $row['user_login'] ?? '' ) );
		$email = trim( (string) ( $row['user_email'] ?? '' ) );
		if ( '' === $login || '' === $email ) {
			return array( 'error' => 'login e e-mail obrigatórios' );
		}
		$display = trim( (string) ( $row['display_name'] ?? $login ) );
		$first   = trim( (string) ( $row['first_name'] ?? '' ) );
		$last    = trim( (string) ( $row['last_name'] ?? '' ) );
		$pass    = (string) ( $row['user_pass'] ?? '' );

		$existing = $dir->findByLogin( $login );
		if ( null === $existing ) {
			$existing = $dir->findByEmail( $email );
		}

		if ( null !== $existing ) {
			$uid = (int) $existing['id'];
			$up  = $dir->update(
				$uid,
				array(
					'email'       => $email,
					'displayName' => $display,
					'firstName'   => $first,
					'lastName'    => $last,
					'role'        => UserRegistryRoles::ROLE_OFFICIAL,
				)
			);
			if ( empty( $up['ok'] ) ) {
				return array( 'error' => (string) ( $up['error'] ?? 'update failed' ) );
			}
			$dir->setRole( $uid, UserRegistryRoles::ROLE_OFFICIAL );
			if ( '' !== $pass ) {
				$dir->setPasswordHash( $uid, $pass );
			}
			return array( 'status' => 'updated' );
		}

		$created = $dir->create(
			array(
				'login'       => $login,
				'email'       => $email,
				'displayName' => $display,
				'firstName'   => $first,
				'lastName'    => $last,
				'role'        => UserRegistryRoles::ROLE_OFFICIAL,
				'password'    => bin2hex( random_bytes( 12 ) ),
			)
		);
		if ( empty( $created['ok'] ) ) {
			return array( 'error' => (string) ( $created['error'] ?? 'create failed' ) );
		}
		if ( '' !== $pass ) {
			$dir->setPasswordHash( (int) $created['id'], $pass );
		}
		return array( 'status' => 'created' );
	}
}
