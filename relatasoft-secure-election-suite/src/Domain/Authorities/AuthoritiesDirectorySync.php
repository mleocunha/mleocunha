<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Authorities;

use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserDirectory;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\UserRegistryRoles;

/**
 * Exportar / importar autoridades eleitorais entre nós (formato AuthoritiesPackage).
 * Sem I/O de rede — o adapter escreve o JSON no courier ou recebe upload.
 */
final class AuthoritiesDirectorySync {

	public const COURIER_FILE = 'authorities.json';

	/**
	 * @param list<array<string,mixed>> $officials Users do papel autoridade (já normalizados).
	 * @param array<int,array{share_index?:int,key_id?:int,threshold_t?:int,total_n?:int}> $shareMeta
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
				$m = $shareMeta[ $uid ];
				$row['share_index']   = (int) ( $m['share_index'] ?? 0 );
				$row['source_key_id'] = (int) ( $m['key_id'] ?? 0 );
				$row['threshold_t']   = (int) ( $m['threshold_t'] ?? 0 );
				$row['total_n']       = (int) ( $m['total_n'] ?? 0 );
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
