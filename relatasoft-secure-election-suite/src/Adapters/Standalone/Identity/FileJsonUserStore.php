<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Identity;

use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Persistence\JsonDocumentStore;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserDirectory;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserProvider;
use RelataSoft\SecureElectionSuite\Painel\Domain\Identity\AccountStatus;
use RelataSoft\SecureElectionSuite\Painel\Domain\Identity\PasswordPolicy;

/**
 * Users + meta duráveis por nó (identity.json) — superfície HTTP standalone.
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
		$login = trim( (string) ( $data['login'] ?? '' ) );
		$email = trim( (string) ( $data['email'] ?? '' ) );
		if ( '' === $login || '' === $email ) {
			return array( 'ok' => false, 'error' => 'login and email required' );
		}
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return array( 'ok' => false, 'error' => 'email inválido' );
		}
		if ( null !== $this->findByLogin( $login ) ) {
			return array( 'ok' => false, 'error' => 'login exists' );
		}
		if ( null !== $this->findByEmail( $email ) ) {
			return array( 'ok' => false, 'error' => 'email exists' );
		}
		$pass = (string) ( $data['password'] ?? '' );
		$pol  = PasswordPolicy::validate( $pass, $login, $email );
		if ( empty( $pol['ok'] ) ) {
			return array( 'ok' => false, 'error' => (string) ( $pol['error'] ?? 'senha inválida' ) );
		}
		$role   = (string) ( $data['role'] ?? 'subscriber' );
		$status = AccountStatus::normalize( isset( $data['status'] ) ? (string) $data['status'] : AccountStatus::ACTIVE );
		$now    = gmdate( 'c' );
		$id     = $this->store->insert(
			self::TABLE,
			array(
				'uuid'         => self::newUuid(),
				'login'        => $login,
				'email'        => $email,
				'displayName'  => (string) ( $data['displayName'] ?? $login ),
				'firstName'    => (string) ( $data['firstName'] ?? '' ),
				'lastName'     => (string) ( $data['lastName'] ?? '' ),
				'roles'        => array( $role ),
				'status'       => $status,
				'createdAt'    => $now,
				'updatedAt'    => $now,
				'passwordHash' => PasswordPolicy::hash( $pass ),
			)
		);
		return array( 'ok' => true, 'id' => $id );
	}

	public function update( int $id, array $data ): array {
		$found = false;
		$error = '';
		$this->store->mutateAuto(
			self::TABLE,
			static function ( array $t ) use ( $id, $data, &$found, &$error ): array {
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
				if ( isset( $data['status'] ) ) {
					$from = AccountStatus::normalize( isset( $t['rows'][ $id ]['status'] ) ? (string) $t['rows'][ $id ]['status'] : null );
					$to   = AccountStatus::normalize( (string) $data['status'] );
					if ( $from !== $to && ! AccountStatus::canTransition( $from, $to ) ) {
						$error = 'transição de estado inválida';
						$found = false;
						return $t;
					}
					$t['rows'][ $id ]['status'] = $to;
				}
				$t['rows'][ $id ]['updatedAt'] = gmdate( 'c' );
				return $t;
			}
		);
		if ( '' !== $error ) {
			return array( 'ok' => false, 'error' => $error );
		}
		return $found ? array( 'ok' => true ) : array( 'ok' => false, 'error' => 'not found' );
	}

	public function setPassword( int $id, string $plaintext ): void {
		$pol = PasswordPolicy::validate( $plaintext );
		if ( empty( $pol['ok'] ) ) {
			throw new \InvalidArgumentException( (string) ( $pol['error'] ?? 'senha inválida' ) );
		}
		$this->setPasswordHash( $id, PasswordPolicy::hash( $plaintext ) );
	}

	public function setPasswordHash( int $id, string $hashOrPlaintext ): void {
		$hash = $hashOrPlaintext;
		// Se ainda for plaintext (import legado sem hash), aplicar política + hash.
		if ( ! str_starts_with( $hash, '$' ) && ! str_starts_with( $hash, 'hash:' ) ) {
			$plain = str_starts_with( $hash, 'plain:' ) ? substr( $hash, 6 ) : $hash;
			$hash  = PasswordPolicy::hash( $plain );
		}
		$this->store->mutateAuto(
			self::TABLE,
			static function ( array $t ) use ( $id, $hash ): array {
				if ( isset( $t['rows'][ $id ] ) ) {
					$t['rows'][ $id ]['passwordHash'] = $hash;
					$t['rows'][ $id ]['updatedAt']    = gmdate( 'c' );
				}
				return $t;
			}
		);
	}

	public function setRole( int $id, string $role ): void {
		$this->update( $id, array( 'role' => $role ) );
	}

	public function addRole( int $id, string $role ): void {
		$role = trim( $role );
		if ( '' === $role ) {
			return;
		}
		$this->store->mutateAuto(
			self::TABLE,
			static function ( array $t ) use ( $id, $role ): array {
				if ( ! isset( $t['rows'][ $id ] ) ) {
					return $t;
				}
				$roles = array_values( array_map( 'strval', (array) ( $t['rows'][ $id ]['roles'] ?? array() ) ) );
				if ( ! in_array( $role, $roles, true ) ) {
					$roles[] = $role;
					$t['rows'][ $id ]['roles']     = $roles;
					$t['rows'][ $id ]['updatedAt'] = gmdate( 'c' );
				}
				return $t;
			}
		);
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
		if ( ! AccountStatus::canAuthenticate( (string) ( $user['status'] ?? AccountStatus::ACTIVE ) ) ) {
			return null;
		}
		$hash = (string) ( $user['passwordHash'] ?? '' );
		if ( ! PasswordPolicy::verify( $hash, $plaintext ) ) {
			// Compat: hashes bcrypt/argon gerados antes da PasswordPolicy (PASSWORD_DEFAULT).
			if ( ! ( str_starts_with( $hash, '$' ) && password_verify( $plaintext, $hash ) ) ) {
				return null;
			}
		}
		if ( PasswordPolicy::needsRehash( $hash ) ) {
			try {
				$this->setPasswordHash( (int) $user['id'], PasswordPolicy::hash( $plaintext ) );
				$user = $this->findById( (int) $user['id'] ) ?? $user;
			} catch ( \Throwable ) {
				// Não bloquear login se rehash falhar.
			}
		}
		return $user;
	}

	/** @deprecated Prefer PasswordPolicy::hash — mantido para callers legados. */
	public static function hashPassword( string $plaintext ): string {
		return PasswordPolicy::hash( $plaintext );
	}

	/** @deprecated Prefer PasswordPolicy::verify */
	public static function passwordMatches( string $stored, string $plaintext ): bool {
		if ( PasswordPolicy::verify( $stored, $plaintext ) ) {
			return true;
		}
		// Compatibilidade transitória com hashes password_hash já persistidos.
		if ( str_starts_with( $stored, '$' ) ) {
			return password_verify( $plaintext, $stored );
		}
		return false;
	}

	/** Vista segura para UI/API — nunca inclui passwordHash. */
	public static function publicView( array $user ): array {
		unset( $user['passwordHash'] );
		return $user;
	}

	public static function newUuid(): string {
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		$hex     = bin2hex( $data );
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}

	/** @param array<string,mixed> $row */
	private function normalize( array $row ): array {
		$now = gmdate( 'c' );
		return array(
			'id'           => (int) $row['id'],
			'uuid'         => (string) ( $row['uuid'] ?? '' ),
			'login'        => (string) ( $row['login'] ?? '' ),
			'email'        => (string) ( $row['email'] ?? '' ),
			'displayName'  => (string) ( $row['displayName'] ?? '' ),
			'firstName'    => (string) ( $row['firstName'] ?? '' ),
			'lastName'     => (string) ( $row['lastName'] ?? '' ),
			'roles'        => array_values( array_map( 'strval', (array) ( $row['roles'] ?? array() ) ) ),
			'status'       => AccountStatus::normalize( isset( $row['status'] ) ? (string) $row['status'] : null ),
			'createdAt'    => (string) ( $row['createdAt'] ?? $now ),
			'updatedAt'    => (string) ( $row['updatedAt'] ?? $now ),
			'passwordHash' => (string) ( $row['passwordHash'] ?? '' ),
		);
	}
}
