<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User;

use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserDirectory;

final class WordPressUserDirectory implements UserDirectory {

	public function findById(int $id): ?array {
		$user = get_userdata( $id );
		return $this->mapUser( $user );
	}

	public function findByLogin(string $login): ?array {
		$user = get_user_by( 'login', $login );
		return $this->mapUser( $user instanceof \WP_User ? $user : null );
	}

	public function findByEmail(string $email): ?array {
		$user = get_user_by( 'email', $email );
		return $this->mapUser( $user instanceof \WP_User ? $user : null );
	}

	public function findIdByMeta(string $metaKey, string $metaValue): int {
		$users = get_users(
			array(
				'meta_key'    => $metaKey, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $metaValue, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);
		if ( empty( $users ) ) {
			return 0;
		}
		return (int) ( is_array( $users ) ? ( $users[0] ?? 0 ) : $users );
	}

	public function listByRole(string $role, int $offset = 0, int $limit = 0): array {
		$args = array(
			'role'    => $role,
			'orderby' => 'ID',
			'order'   => 'ASC',
		);
		if ( $limit > 0 ) {
			$args['number'] = $limit;
			$args['offset'] = max( 0, $offset );
		} elseif ( $offset > 0 ) {
			$args['offset'] = $offset;
		}
		$users = get_users( $args );
		$out   = array();
		foreach ( is_array( $users ) ? $users : array() as $user ) {
			$row = $this->mapUser( $user );
			if ( null !== $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function countByRole(string $role): int {
		$users = get_users(
			array(
				'role'        => $role,
				'fields'      => 'ID',
				'number'      => -1,
				'count_total' => false,
			)
		);
		return is_array( $users ) ? count( $users ) : 0;
	}

	public function create(array $data): array {
		$payload = array(
			'user_login'   => (string) ( $data['login'] ?? '' ),
			'user_email'   => (string) ( $data['email'] ?? '' ),
			'user_pass'    => (string) ( $data['password'] ?? '' ),
			'display_name' => (string) ( $data['displayName'] ?? ( $data['login'] ?? '' ) ),
			'first_name'   => (string) ( $data['firstName'] ?? '' ),
			'last_name'    => (string) ( $data['lastName'] ?? '' ),
			'role'         => (string) ( $data['role'] ?? 'subscriber' ),
		);
		$result = wp_insert_user( $payload );
		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'error' => $result->get_error_message() );
		}
		return array( 'ok' => true, 'id' => (int) $result );
	}

	public function update(int $id, array $data): array {
		$payload = array( 'ID' => $id );
		if ( array_key_exists( 'email', $data ) ) {
			$payload['user_email'] = (string) $data['email'];
		}
		if ( array_key_exists( 'displayName', $data ) ) {
			$payload['display_name'] = (string) $data['displayName'];
		}
		if ( array_key_exists( 'firstName', $data ) ) {
			$payload['first_name'] = (string) $data['firstName'];
		}
		if ( array_key_exists( 'lastName', $data ) ) {
			$payload['last_name'] = (string) $data['lastName'];
		}
		if ( array_key_exists( 'role', $data ) ) {
			$payload['role'] = (string) $data['role'];
		}
		$result = wp_update_user( $payload );
		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'error' => $result->get_error_message() );
		}
		if ( isset( $data['role'] ) ) {
			$this->setRole( $id, (string) $data['role'] );
		}
		return array( 'ok' => true );
	}

	public function setPassword(int $id, string $plaintext): void {
		wp_set_password( $plaintext, $id );
	}

	public function setPasswordHash(int $id, string $hashOrPlaintext): void {
		global $wpdb;
		if ( $id <= 0 || '' === $hashOrPlaintext ) {
			return;
		}
		if ( ! preg_match( '/^(\$P\$|\$2[ayb]\$|\$wp)/', $hashOrPlaintext ) ) {
			wp_set_password( $hashOrPlaintext, $id );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->users,
			array( 'user_pass' => $hashOrPlaintext ),
			array( 'ID' => $id ),
			array( '%s' ),
			array( '%d' )
		);
		clean_user_cache( $id );
	}

	public function setRole(int $id, string $role): void {
		$user = new \WP_User( $id );
		if ( $user->exists() ) {
			$user->set_role( $role );
		}
	}

	public function getMeta(int $id, string $key): string {
		return (string) get_user_meta( $id, $key, true );
	}

	public function setMeta(int $id, string $key, string $value): void {
		update_user_meta( $id, $key, $value );
	}

	/** @return array<string,mixed>|null */
	private function mapUser( ?\WP_User $user ): ?array {
		if ( ! $user || ! $user->exists() ) {
			return null;
		}
		return array(
			'id'           => (int) $user->ID,
			'login'        => (string) $user->user_login,
			'email'        => (string) $user->user_email,
			'displayName'  => (string) $user->display_name,
			'firstName'    => (string) get_user_meta( $user->ID, 'first_name', true ),
			'lastName'     => (string) get_user_meta( $user->ID, 'last_name', true ),
			'roles'        => array_values( array_map( 'strval', (array) $user->roles ) ),
			'passwordHash' => (string) $user->user_pass,
		);
	}
}
