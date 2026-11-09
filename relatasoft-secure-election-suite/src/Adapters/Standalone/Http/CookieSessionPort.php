<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http;

use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Identity\FileJsonUserStore;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Persistence\JsonDocumentStore;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Session\SessionPort;

/**
 * Sessão por cookie + blob JSON durável (sessions em identity.json).
 */
final class CookieSessionPort implements SessionPort {

	public const COOKIE = 've_session';
	private const BLOB  = 'sessions';
	private const TTL_ABSOLUTE = 86400; // 24h máximo
	private const TTL_IDLE     = 14400; // 4h inatividade

	private int $userId = 0;
	private string $token = '';

	public function __construct(
		private readonly JsonDocumentStore $store,
		private readonly FileJsonUserStore $users,
	) {}

	public function hydrateFromCookie( ?string $token ): void {
		// Sempre repor estado em memória — o mesmo CookieSessionPort vive entre pedidos no PHPUnit.
		$this->userId = 0;
		$this->token  = '';
		$this->users->setCurrentUserId( 0 );
		if ( null === $token || '' === $token ) {
			return;
		}
		$sessions = $this->store->blob( self::BLOB );
		$row      = $sessions[ $token ] ?? null;
		if ( ! is_array( $row ) ) {
			return;
		}
		$exp  = (int) ( $row['expires'] ?? 0 );
		$idle = (int) ( $row['idleExpires'] ?? $exp );
		$uid  = (int) ( $row['userId'] ?? 0 );
		$now  = time();
		if ( $exp < $now || $idle < $now || $uid <= 0 || null === $this->users->findById( $uid ) ) {
			unset( $sessions[ $token ] );
			$this->store->writeBlob( self::BLOB, $sessions );
			return;
		}
		$user = $this->users->findById( $uid );
		$status = (string) ( $user['status'] ?? 'active' );
		if ( 'active' !== $status ) {
			unset( $sessions[ $token ] );
			$this->store->writeBlob( self::BLOB, $sessions );
			return;
		}
		// Sliding idle window.
		$row['idleExpires'] = $now + self::TTL_IDLE;
		$sessions[ $token ] = $row;
		$this->store->writeBlob( self::BLOB, $sessions );
		$this->userId = $uid;
		$this->token  = $token;
		$this->users->setCurrentUserId( $uid );
	}

	public function login( int $userId ): string {
		// Rotação: não reutilizar token anterior.
		$token    = bin2hex( random_bytes( 32 ) );
		$sessions = $this->store->blob( self::BLOB );
		$now      = time();
		$sessions[ $token ] = array(
			'userId'      => $userId,
			'created'     => $now,
			'expires'     => $now + self::TTL_ABSOLUTE,
			'idleExpires' => $now + self::TTL_IDLE,
		);
		$this->store->writeBlob( self::BLOB, $sessions );
		$this->userId = $userId;
		$this->token  = $token;
		$this->users->setCurrentUserId( $userId );
		return $token;
	}

	public function logout( ?string $token ): void {
		if ( $token ) {
			$sessions = $this->store->blob( self::BLOB );
			unset( $sessions[ $token ] );
			$this->store->writeBlob( self::BLOB, $sessions );
		}
		$this->userId = 0;
		$this->token  = '';
		$this->users->setCurrentUserId( 0 );
	}

	/** Revoga todas as sessões de um utilizador (logout global / alteração de senha / bloqueio). */
	public function revokeAllForUser( int $userId ): int {
		$sessions = $this->store->blob( self::BLOB );
		$n        = 0;
		foreach ( $sessions as $tok => $row ) {
			if ( is_array( $row ) && (int) ( $row['userId'] ?? 0 ) === $userId ) {
				unset( $sessions[ $tok ] );
				++$n;
			}
		}
		$this->store->writeBlob( self::BLOB, $sessions );
		if ( $this->userId === $userId ) {
			$this->userId = 0;
			$this->token  = '';
			$this->users->setCurrentUserId( 0 );
		}
		return $n;
	}

	/** @return list<array{token_prefix:string,created:int,expires:int,idleExpires:int}> */
	public function listForUser( int $userId ): array {
		$out = array();
		foreach ( $this->store->blob( self::BLOB ) as $tok => $row ) {
			if ( ! is_array( $row ) || (int) ( $row['userId'] ?? 0 ) !== $userId ) {
				continue;
			}
			$out[] = array(
				'token_prefix' => substr( (string) $tok, 0, 8 ),
				'created'      => (int) ( $row['created'] ?? 0 ),
				'expires'      => (int) ( $row['expires'] ?? 0 ),
				'idleExpires'  => (int) ( $row['idleExpires'] ?? 0 ),
				'current'      => (string) $tok === $this->token,
			);
		}
		return $out;
	}

	public function currentToken(): string {
		return $this->token;
	}

	public function currentUserId(): int {
		return $this->userId;
	}

	public function isAuthenticated(): bool {
		return $this->userId > 0;
	}

	public function assertCurrentUser( int $expectedUserId ): void {
		if ( $this->userId !== $expectedUserId ) {
			throw new \RuntimeException( 'Session user mismatch.' );
		}
	}

	/** Atributos Set-Cookie seguros (Secure só com HTTPS / VE_PUBLIC_BASE https). */
	public static function cookieAttributes( bool $clear = false ): string {
		$secure = self::shouldUseSecureCookie();
		$base   = 'Path=/; HttpOnly; SameSite=Lax' . ( $secure ? '; Secure' : '' );
		if ( $clear ) {
			return $base . '; Max-Age=0';
		}
		return $base . '; Max-Age=' . self::TTL_ABSOLUTE;
	}

	public static function shouldUseSecureCookie(): bool {
		$base = (string) ( getenv( 'VE_PUBLIC_BASE' ) ?: '' );
		return str_starts_with( $base, 'https://' );
	}
}
