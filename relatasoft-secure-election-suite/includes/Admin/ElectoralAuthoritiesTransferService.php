<?php
/**
 * Export / import electoral authorities between operation modes.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\KeyAuthority\KeyRepository;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Transfer service: Key Authority exports; Voting / Tallying import the same JSON.
 */
final class ElectoralAuthoritiesTransferService {

	/**
	 * Collect authorities (WP role editor) into a portable package.
	 *
	 * @return array<string,mixed>
	 */
	public static function build_export_package(): array {
		$users = get_users(
			array(
				'role'    => Capability::RSES_OFFICIAL_ROLE,
				'orderby' => 'login',
				'order'   => 'ASC',
			)
		);

		$share_meta = self::share_index_by_user();
		$rows       = array();
		foreach ( $users as $user ) {
			/** @var \WP_User $user */
			$row = array(
				'user_login'   => (string) $user->user_login,
				'user_email'   => (string) $user->user_email,
				'display_name' => (string) $user->display_name,
				'first_name'   => (string) get_user_meta( $user->ID, 'first_name', true ),
				'last_name'    => (string) get_user_meta( $user->ID, 'last_name', true ),
				'role'         => Capability::RSES_OFFICIAL_ROLE,
				'user_pass'    => (string) $user->user_pass,
				'source_user_id' => (int) $user->ID,
			);
			if ( isset( $share_meta[ $user->ID ] ) ) {
				$row['share_index']    = $share_meta[ $user->ID ]['share_index'];
				$row['source_key_id']  = $share_meta[ $user->ID ]['key_id'];
				$row['threshold_t']    = $share_meta[ $user->ID ]['threshold_t'];
				$row['total_n']        = $share_meta[ $user->ID ]['total_n'];
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
	 * Import package: create/update editor accounts; restore portable password hashes.
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
		$login = sanitize_user( (string) ( $row['user_login'] ?? '' ), true );
		$email = sanitize_email( (string) ( $row['user_email'] ?? '' ) );
		if ( '' === $login || '' === $email ) {
			return new \WP_Error( 'rses_ea_required', __( 'Login e e-mail são obrigatórios.', 'relatasoft-secure-election-suite' ) );
		}

		$display = sanitize_text_field( (string) ( $row['display_name'] ?? $login ) );
		$first   = sanitize_text_field( (string) ( $row['first_name'] ?? '' ) );
		$last    = sanitize_text_field( (string) ( $row['last_name'] ?? '' ) );
		$pass    = (string) ( $row['user_pass'] ?? '' );

		$existing = get_user_by( 'login', $login );
		if ( ! $existing ) {
			$by_email = get_user_by( 'email', $email );
			if ( $by_email ) {
				$existing = $by_email;
			}
		}

		if ( $existing ) {
			$uid = (int) $existing->ID;
			wp_update_user(
				array(
					'ID'           => $uid,
					'user_email'   => $email,
					'display_name' => $display,
					'first_name'   => $first,
					'last_name'    => $last,
					'role'         => Capability::RSES_OFFICIAL_ROLE,
				)
			);
			$u = new \WP_User( $uid );
			$u->set_role( Capability::RSES_OFFICIAL_ROLE );
			if ( $pass !== '' ) {
				self::apply_password_hash( $uid, $pass );
			}
			return 'updated';
		}

		$create = array(
			'user_login'   => $login,
			'user_email'   => $email,
			'display_name' => $display,
			'first_name'   => $first,
			'last_name'    => $last,
			'role'         => Capability::RSES_OFFICIAL_ROLE,
			'user_pass'    => wp_generate_password( 24, true, true ),
		);
		$uid = wp_insert_user( $create );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		if ( $pass !== '' ) {
			self::apply_password_hash( (int) $uid, $pass );
		}
		return 'created';
	}

	/**
	 * Apply a portable WordPress password hash without re-hashing.
	 */
	private static function apply_password_hash( int $user_id, string $hash ): void {
		global $wpdb;
		if ( $user_id <= 0 || '' === $hash ) {
			return;
		}
		// Only accept phpass / WP modern hashes (not plaintext).
		if ( ! preg_match( '/^(\$P\$|\$2[ayb]\$|\$wp)/', $hash ) ) {
			wp_set_password( $hash, $user_id );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->users,
			array( 'user_pass' => $hash ),
			array( 'ID' => $user_id ),
			array( '%s' ),
			array( '%d' )
		);
		clean_user_cache( $user_id );
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
					'share_index'  => (int) ( $share->share_index ?? 0 ),
					'key_id'       => (int) $key->id,
					'threshold_t'  => (int) ( $key->threshold_t ?? 0 ),
					'total_n'      => (int) ( $key->total_n ?? 0 ),
				);
			}
		}
		return $out;
	}
}
