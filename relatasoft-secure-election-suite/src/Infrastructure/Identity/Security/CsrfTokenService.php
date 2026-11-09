<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Security;

/**
 * CSRF com token aleatório ligado a um segredo de nó + id de sessão.
 * Não depende de $_SESSION PHP.
 */
final class CsrfTokenService {

	public function __construct(
		private readonly string $nodeSecret,
	) {}

	public function issue( string $sessionBinding ): string {
		$nonce = bin2hex( random_bytes( 16 ) );
		$mac   = hash_hmac( 'sha256', $sessionBinding . '|' . $nonce, $this->nodeSecret );
		return $nonce . '.' . $mac;
	}

	public function validate( string $sessionBinding, string $token ): bool {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		[ $nonce, $mac ] = $parts;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/', $mac ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $sessionBinding . '|' . $nonce, $this->nodeSecret );
		return hash_equals( $expected, $mac );
	}

	/** Binding anónimo (pré-login) a partir do cookie de sessão ou IP+UA truncado. */
	public static function anonymousBinding( string $cookieOrEmpty, string $remoteHint = '' ): string {
		$base = '' !== $cookieOrEmpty ? $cookieOrEmpty : ( 'anon:' . substr( hash( 'sha256', $remoteHint ), 0, 32 ) );
		return 'bind:' . $base;
	}
}
