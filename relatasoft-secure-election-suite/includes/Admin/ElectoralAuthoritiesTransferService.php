<?php
/**
 * Export / import electoral authorities between operation modes.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\KeyAuthority\KeyRepository;
use RelataSoft\SecureElectionSuite\Painel\Application\Identity\IdentityGateway;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Transfer service: Key Authority exports; Voting / Tallying import the same JSON.
 *
 * User CRUD goes through A3 {@see IdentityGateway} UserDirectory.
 */
final class ElectoralAuthoritiesTransferService {

	/**
	 * Collect authorities (official role) into a portable package.
	 *
	 * @return array<string,mixed>
	 */
	public static function build_export_package(): array {
		$dir   = IdentityGateway::get()->users;
		$users = $dir->listByRole( Capability::RSES_OFFICIAL_ROLE );

		$share_meta = self::share_index_by_user();
		$rows       = array();
		foreach ( $users as $user ) {
			$uid = (int) $user['id'];
			$row = array(
				'user_login'     => (string) $user['login'],
				'user_email'     => (string) $user['email'],
				'display_name'   => (string) $user['displayName'],
				'first_name'     => (string) $user['firstName'],
				'last_name'      => (string) $user['lastName'],
				'role'           => Capability::RSES_OFFICIAL_ROLE,
				'user_pass'      => (string) $user['passwordHash'],
				'source_user_id' => $uid,
			);
			if ( isset( $share_meta[ $uid ] ) ) {
				$row['share_index']   = $share_meta[ $uid ]['share_index'];
				$row['source_key_id'] = $share_meta[ $uid ]['key_id'];
				$row['threshold_t']   = $share_meta[ $uid ]['threshold_t'];
				$row['total_n']       = $share_meta[ $uid ]['total_n'];
			}
			$rows[] = $row;
		}

		return ElectoralAuthoritiesPackage::build(
			array(
				'exported_at'    => gmdate( 'c' ),
				'source_site'    => (string) home_url( '/' ),
				'source_mode'    => (string) ModeLock::rses_get_mode(),
				'plugin_version' => defined( 'RSES_VERSION' ) ? RSES_VERSION : '',
				'authorities'    => $rows,
			)
		);
	}

	/**
	 * Import package: create/update official accounts; restore portable password hashes.
	 *
	 * @param array<string,mixed> $package Validated package.
	 * @return array{created:int,updated:int,skipped:int,errors:list<string>}
	 */
	public static function import_package( array $package ): array {
		$result = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		$ok = ElectoralAuthoritiesPackage::validate( $package );
		if ( is_wp_error( $ok ) ) {
			$result['errors'][] = $ok->get_error_message();
			return $result;
		}

		foreach ( $package['authorities'] as $i => $row ) {
			if ( ! is_array( $row ) ) {
				++$result['skipped'];
				continue;
			}
			$outcome = self::upsert_authority( $row );
			if ( is_wp_error( $outcome ) ) {
				$result['errors'][] = sprintf(
					/* translators: 1: index, 2: error */
					__( 'Autoridade #%1$d: %2$s', 'relatasoft-secure-election-suite' ),
					(int) $i + 1,
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

		if ( class_exists( AuditLogger::class ) ) {
			AuditLogger::rses_log(
				'electoral_authorities_import',
				'electoral_authorities',
				null,
				array(
					'created' => $result['created'],
					'updated' => $result['updated'],
					'skipped' => $result['skipped'],
					'errors'  => count( $result['errors'] ),
				)
			);
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return string|\WP_Error created|updated|skipped
	 */
	private static function upsert_authority( array $row ) {
		$dir     = IdentityGateway::get()->users;
		$login   = sanitize_user( (string) ( $row['user_login'] ?? '' ), true );
		$email   = sanitize_email( (string) ( $row['user_email'] ?? '' ) );
		if ( '' === $login || '' === $email ) {
			return new \WP_Error( 'rses_ea_required', __( 'Login e e-mail são obrigatórios.', 'relatasoft-secure-election-suite' ) );
		}

		$display = sanitize_text_field( (string) ( $row['display_name'] ?? $login ) );
		$first   = sanitize_text_field( (string) ( $row['first_name'] ?? '' ) );
		$last    = sanitize_text_field( (string) ( $row['last_name'] ?? '' ) );
		$pass    = (string) ( $row['user_pass'] ?? '' );

		$existing = $dir->findByLogin( $login );
		if ( null === $existing ) {
			$by_email = $dir->findByEmail( $email );
			if ( null !== $by_email ) {
				$existing = $by_email;
			}
		}

		if ( null !== $existing ) {
			$uid     = (int) $existing['id'];
			$updated = $dir->update(
				$uid,
				array(
					'email'       => $email,
					'displayName' => $display,
					'firstName'   => $first,
					'lastName'    => $last,
					'role'        => Capability::RSES_OFFICIAL_ROLE,
				)
			);
			if ( ! $updated['ok'] ) {
				return new \WP_Error( 'rses_ea_update', $updated['error'] );
			}
			$dir->setRole( $uid, Capability::RSES_OFFICIAL_ROLE );
			if ( $pass !== '' ) {
				$dir->setPasswordHash( $uid, $pass );
			}
			return 'updated';
		}

		$created = $dir->create(
			array(
				'login'       => $login,
				'email'       => $email,
				'displayName' => $display,
				'firstName'   => $first,
				'lastName'    => $last,
				'role'        => Capability::RSES_OFFICIAL_ROLE,
				'password'    => wp_generate_password( 24, true, true ),
			)
		);
		if ( ! $created['ok'] ) {
			return new \WP_Error( 'rses_ea_create', $created['error'] );
		}
		if ( $pass !== '' ) {
			$dir->setPasswordHash( (int) $created['id'], $pass );
		}
		return 'created';
	}

	/**
	 * Latest share assignment metadata keyed by user ID (for export enrichment only).
	 *
	 * @return array<int,array{share_index:int,key_id:int,threshold_t:int,total_n:int}>
	 */
	private static function share_index_by_user(): array {
		$out  = array();
		$keys = KeyRepository::rses_list_active();
		foreach ( $keys as $key ) {
			$shares = KeyRepository::rses_get_shares( (int) $key->id );
			foreach ( $shares as $share ) {
				$uid = (int) ( $share->official_user_id ?? 0 );
				if ( $uid <= 0 ) {
					continue;
				}
				$out[ $uid ] = array(
					'share_index' => (int) ( $share->share_index ?? 0 ),
					'key_id'      => (int) $key->id,
					'threshold_t' => (int) ( $key->threshold_t ?? 0 ),
					'total_n'     => (int) ( $key->total_n ?? 0 ),
				);
			}
		}
		return $out;
	}
}
