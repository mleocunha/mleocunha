<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Identity;

use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Persistence\JsonDocumentStore;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserDirectory;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserProvider;

/**
 * Users + meta duráveis por nó (identity.json) — Adapter #2 HTTP.
 */
final class FileJsonUserStore implements UserProvider, UserDirectory {

	private const TABLE = 'users';
	private const META  = 'user_meta';

	private int $currentId = 0;

	public function __construct(
		private readonly JsonDocumentStore $store,
	) {}

	public function documentStore(): JsonDocumentStore {
		return $this->store;
	}

	public static function open( string $path ): self {
		return new self( new JsonDocumentStore( $path ) );
	}

	public function setCurrentUserId( int $id ): void {
		$this->currentId = $id;
	}

	public function currentUserId(): int {
		return $this->currentId;
	}

	public function isAuthenticated(): bool {
		return $this->currentId > 0 && null !== $this->findById( $this->currentId );
	}

	public function currentRoles(): array {
		$row = $this->findById( $this->currentId );
		return $row ? array_map( 'strval', (array) ( $row['roles'] ?? array() ) ) : array();
	}

	public function findById( int $id ): ?array {
		$row = $this->store->find( self::TABLE, $id );
		return $row ? $this->normalize( $row ) : null;
	}

	public function findByLogin( string $login ): ?array {
		foreach ( $this->store->all( self::TABLE ) as $row ) {
			if ( ( $row['login'] ?? '' ) === $login ) {
				return $this->normalize( $row );
			}
		}
		return null;
	}

	public function findByEmail( string $email ): ?array {
		foreach ( $this->store->all( self::TABLE ) as $row ) {
			if ( ( $row['email'] ?? '' ) === $email ) {
				return $this->normalize( $row );
			}
		}
		return null;
	}

	public function findIdByMeta( string $metaKey, string $metaValue ): int {
		$bag = $this->store->blob( self::META );
		foreach ( $bag as $id => $meta ) {
			if ( is_array( $meta ) && ( $meta[ $metaKey ] ?? null ) === $metaValue ) {
				return (int) $id;
			}
		}
		return 0;
	}

	public function listByRole( string $role, int $offset = 0, int $limit = 0 ): array {
		$out = array();
		foreach ( $this->store->all( self::TABLE ) as $row ) {
			$roles = array_map( 'strval', (array) ( $row['roles'] ?? array() ) );
			if ( in_array( $role, $roles, true ) ) {
				$out[] = $this->normalize( $row );
			}
		}
		usort( $out, static fn( $a, $b ) => $a['id'] <=> $b['id'] );
		if ( $offset > 0 ) {
			$out = array_slice( $out, $offset );
		}
		if ( $limit > 0 ) {
			$out = array_slice( $out, 0, $limit );
		}
		return array_values( $out );
	}

	public function countByRole( string $role ): int {
		return count( $this->listByRole( $role ) );
	}

	public function create( array $data ): array {
		$login = (string) ( $data['login'] ?? '' );
		$email = (string) ( $data['email'] ?? '' );
		if ( '' === $login || '' === $email ) {
			return array( 'ok' => false, 'error' => 'login and email required' );
		}
		if ( null !== $this->findByLogin( $login ) ) {
			return array( 'ok' => false, 'error' => 'login exists' );
		}
		$role = (string) ( $data['role'] ?? 'subscriber' );
		$pass = (string) ( $data['password'] ?? '' );
		$id   = $this->store->insert(
			self::TABLE,
			array(
				'login'        => $login,
				'email'        => $email,
				'displayName'  => (string) ( $data['displayName'] ?? $login ),
				'firstName'    => (string) ( $data['firstName'] ?? '' ),
				'lastName'     => (string) ( $data['lastName'] ?? '' ),
				'roles'        => array( $role ),
				'passwordHash' => self::hashPassword( $pass ),
			)
		);
		return array( 'ok' => true, 'id' => $id );
	}

	public function update( int $id, array $data ): array {
		$found = false;
		$this->store->mutateAuto(
			self::TABLE,
			static function ( array $t ) use ( $id, $data, &$found ): array {
				if ( ! isset( $t['rows'][ $id ] ) ) {
					return $t;
				}
				$found = true;
				foreach ( array( 'email' => 'email', 'displayName' => 'displayName', 'firstName' => 'firstName', 'lastName' => 'lastName' ) as $in => $field ) {
					if ( array_key_exists( $in, $data ) ) {
						$t['rows'][ $id ][ $field ] = (string) $data[ $in ];
					}
				}
				if ( isset( $data['role'] ) ) {
					$t['rows'][ $id ]['roles'] = array( (string) $data['role'] );
				}
				return $t;
			}
		);
		return $found ? array( 'ok' => true ) : array( 'ok' => false, 'error' => 'not found' );
	}

	public function setPassword( int $id, string $plaintext ): void {
		$this->setPasswordHash( $id, self::hashPassword( $plaintext ) );
	}

	public function setPasswordHash( int $id, string $hashOrPlaintext ): void {
		$this->store->mutateAuto(
			self::TABLE,
			static function ( array $t ) use ( $id, $hashOrPlaintext ): array {
				if ( isset( $t['rows'][ $id ] ) ) {
					$t['rows'][ $id ]['passwordHash'] = $hashOrPlaintext;
				}
				return $t;
			}
		);
	}

	public function setRole( int $id, string $role ): void {
		$this->update( $id, array( 'role' => $role ) );
	}

	public function getMeta( int $id, string $key ): string {
		$bag = $this->store->blob( self::META );
		$m   = $bag[ (string) $id ] ?? $bag[ $id ] ?? array();
		return is_array( $m ) ? (string) ( $m[ $key ] ?? '' ) : '';
	}

	public function setMeta( int $id, string $key, string $value ): void {
		$bag = $this->store->blob( self::META );
		$sid = (string) $id;
		if ( ! isset( $bag[ $sid ] ) || ! is_array( $bag[ $sid ] ) ) {
			$bag[ $sid ] = array();
		}
		$bag[ $sid ][ $key ] = $value;
		$this->store->writeBlob( self::META, $bag );
	}

	public function verifyPassword( string $login, string $plaintext ): ?array {
		$user = $this->findByLogin( $login );
		if ( null === $user ) {
			return null;
		}
		$hash = (string) ( $user['passwordHash'] ?? '' );
		if ( ! self::passwordMatches( $hash, $plaintext ) ) {
			return null;
		}
		return $user;
	}

	public static function hashPassword( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		return password_hash( $plaintext, PASSWORD_DEFAULT ) ?: ( 'plain:' . $plaintext );
	}

	public static function passwordMatches( string $stored, string $plaintext ): bool {
		if ( '' === $stored ) {
			return false;
		}
		if ( str_starts_with( $stored, 'plain:' ) ) {
			return hash_equals( substr( $stored, 6 ), $plaintext );
		}
		if ( str_starts_with( $stored, '$' ) ) {
			return password_verify( $plaintext, $stored );
		}
		return hash_equals( $stored, $plaintext );
	}

	/** @param array<string,mixed> $row */
	private function normalize( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'login'        => (string) ( $row['login'] ?? '' ),
			'email'        => (string) ( $row['email'] ?? '' ),
			'displayName'  => (string) ( $row['displayName'] ?? '' ),
			'firstName'    => (string) ( $row['firstName'] ?? '' ),
			'lastName'     => (string) ( $row['lastName'] ?? '' ),
			'roles'        => array_values( array_map( 'strval', (array) ( $row['roles'] ?? array() ) ) ),
			'passwordHash' => (string) ( $row['passwordHash'] ?? '' ),
		);
	}
}
