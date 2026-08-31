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
	private const TTL   = 86400; // 24h

	private int $userId = 0;

	public function __construct(
		private readonly JsonDocumentStore $store,
		private readonly FileJsonUserStore $users,
	) {}

	public function hydrateFromCookie( ?string $token ): void {
		if ( null === $token || '' === $token ) {
			return;
		}
		$sessions = $this->store->blob( self::BLOB );
		$row      = $sessions[ $token ] ?? null;
		if ( ! is_array( $row ) ) {
			return;
		}
		$exp = (int) ( $row['expires'] ?? 0 );
		$uid = (int) ( $row['userId'] ?? 0 );
		if ( $exp < time() || $uid <= 0 || null === $this->users->findById( $uid ) ) {
			unset( $sessions[ $token ] );
			$this->store->writeBlob( self::BLOB, $sessions );
			return;
		}
		$this->userId = $uid;
		$this->users->setCurrentUserId( $uid );
	}

	public function login( int $userId ): string {
		$token = bin2hex( random_bytes( 24 ) );
		$sessions = $this->store->blob( self::BLOB );
		$sessions[ $token ] = array(
			'userId'  => $userId,
			'expires' => time() + self::TTL,
		);
		$this->store->writeBlob( self::BLOB, $sessions );
		$this->userId = $userId;
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
		$this->users->setCurrentUserId( 0 );
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
}
