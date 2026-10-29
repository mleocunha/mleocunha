<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Security;

use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Persistence\JsonDocumentStore;

/**
 * Rate limit progressivo por chave (login normalizado + origem).
 * Bloqueios temporários — evita DoS permanente a contas legítimas.
 */
final class LoginRateLimiter {

	private const BLOB = 'login_throttle';
	private const WINDOW = 900; // 15 min
	private const MAX_FAILURES = 8;

	public function __construct(
		private readonly JsonDocumentStore $store,
	) {}

	public function isBlocked( string $key ): bool {
		$row = $this->row( $key );
		$until = (int) ( $row['blocked_until'] ?? 0 );
		return $until > time();
	}

	public function remainingBlockSeconds( string $key ): int {
		$row = $this->row( $key );
		$until = (int) ( $row['blocked_until'] ?? 0 );
		return max( 0, $until - time() );
	}

	public function recordFailure( string $key ): void {
		$bag = $this->store->blob( self::BLOB );
		$row = is_array( $bag[ $key ] ?? null ) ? $bag[ $key ] : array( 'failures' => 0, 'first' => time() );
		$now = time();
		$first = (int) ( $row['first'] ?? $now );
		if ( ( $now - $first ) > self::WINDOW ) {
			$row = array( 'failures' => 0, 'first' => $now );
		}
		$row['failures'] = (int) ( $row['failures'] ?? 0 ) + 1;
		$row['last']     = $now;
		if ( $row['failures'] >= self::MAX_FAILURES ) {
			// Backoff: 2^(failures-MAX) minutos, teto 30 min.
			$exp = min( 30, (int) ( 2 ** max( 0, $row['failures'] - self::MAX_FAILURES ) ) );
			$row['blocked_until'] = $now + ( $exp * 60 );
		}
		$bag[ $key ] = $row;
		$this->store->writeBlob( self::BLOB, $bag );
	}

	public function clear( string $key ): void {
		$bag = $this->store->blob( self::BLOB );
		unset( $bag[ $key ] );
		$this->store->writeBlob( self::BLOB, $bag );
	}

	public static function keyFor( string $login, string $originHint = '' ): string {
		return hash( 'sha256', mb_strtolower( trim( $login ), 'UTF-8' ) . '|' . $originHint );
	}

	/** @return array<string,mixed> */
	private function row( string $key ): array {
		$bag = $this->store->blob( self::BLOB );
		$row = $bag[ $key ] ?? null;
		return is_array( $row ) ? $row : array();
	}
}
