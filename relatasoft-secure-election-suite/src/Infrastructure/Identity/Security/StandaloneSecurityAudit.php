<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Security;

use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Persistence\JsonDocumentStore;

/**
 * Trilha append-only de eventos de identidade no nó standalone.
 * Nunca registra senhas, tokens, code_verifier, id_token ou hashes.
 */
final class StandaloneSecurityAudit {

	private const BLOB = 'security_events';

	/** @var list<string> */
	private const FORBIDDEN = array(
		'password', 'passwordHash', 'token', 'code', 'code_verifier',
		'id_token', 'access_token', 'refresh_token', 'share_value',
		'private_x', 'csrf', 'authorization',
	);

	public function __construct(
		private readonly JsonDocumentStore $store,
	) {}

	/**
	 * @param array<string,mixed> $context
	 */
	public function record( string $type, ?int $actorUserId, array $context = array() ): string {
		$id   = bin2hex( random_bytes( 12 ) );
		$bag  = $this->store->blob( self::BLOB );
		$prev = is_array( $bag ) && $bag !== array() ? (string) ( end( $bag )['hash'] ?? '' ) : '';
		$entry = array(
			'id'        => $id,
			'ts'        => gmdate( 'c' ),
			'type'      => $type,
			'actor'     => $actorUserId,
			'context'   => self::sanitize( $context ),
			'prev_hash' => $prev,
		);
		$entry['hash'] = hash( 'sha256', json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ?: '' );
		$bag[]         = $entry;
		// Retenção operacional: manter últimos 5000 eventos no nó.
		if ( count( $bag ) > 5000 ) {
			$bag = array_values( array_slice( $bag, -5000 ) );
		}
		$this->store->writeBlob( self::BLOB, $bag );
		return $id;
	}

	/** @return list<array<string,mixed>> */
	public function recent( int $limit = 50 ): array {
		$bag = $this->store->blob( self::BLOB );
		if ( ! is_array( $bag ) ) {
			return array();
		}
		return array_values( array_slice( $bag, -max( 1, $limit ) ) );
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $context ): array {
		$out = array();
		foreach ( $context as $k => $v ) {
			$key = strtolower( (string) $k );
			foreach ( self::FORBIDDEN as $bad ) {
				if ( str_contains( $key, $bad ) ) {
					continue 2;
				}
			}
			if ( is_scalar( $v ) || null === $v ) {
				$out[ (string) $k ] = $v;
			} elseif ( is_array( $v ) ) {
				$out[ (string) $k ] = self::sanitize( $v );
			}
		}
		return $out;
	}
}
