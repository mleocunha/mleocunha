<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Federation;

use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Persistence\JsonDocumentStore;

/**
 * Vínculos federados (issuer + subject → userId interno).
 * Não vincula automaticamente por e-mail/CPF.
 */
final class FederatedIdentityStore {

	private const BLOB = 'federated_identities';

	public const PROVIDER_GOVBR = 'govbr';

	public function __construct(
		private readonly JsonDocumentStore $store,
	) {}

	public function findUserId( string $provider, string $issuer, string $subject ): int {
		$key = self::key( $provider, $issuer, $subject );
		$bag = $this->store->blob( self::BLOB );
		$row = $bag[ $key ] ?? null;
		if ( ! is_array( $row ) ) {
			return 0;
		}
		if ( ( $row['status'] ?? '' ) !== 'active' ) {
			return 0;
		}
		return (int) ( $row['userId'] ?? 0 );
	}

	/**
	 * @param array<string,mixed> $meta Metadados mínimos (nível conta, etc.) — sem tokens.
	 */
	public function link( string $provider, string $issuer, string $subject, int $userId, array $meta = array() ): void {
		if ( $userId <= 0 || '' === $subject || '' === $issuer ) {
			throw new \InvalidArgumentException( 'Vínculo federado inválido.' );
		}
		$key = self::key( $provider, $issuer, $subject );
		$bag = $this->store->blob( self::BLOB );
		// Impedir account takeover: subject já ligado a outro user.
		$existing = $bag[ $key ] ?? null;
		if ( is_array( $existing ) && (int) ( $existing['userId'] ?? 0 ) > 0 && (int) $existing['userId'] !== $userId ) {
			throw new \RuntimeException( 'Identidade federada já vinculada a outra conta.' );
		}
		$bag[ $key ] = array(
			'provider'   => $provider,
			'issuer'     => $issuer,
			'subject'    => $subject,
			'userId'     => $userId,
			'status'     => 'active',
			'linkedAt'   => gmdate( 'c' ),
			'lastAuthAt' => gmdate( 'c' ),
			'meta'       => $meta,
		);
		$this->store->writeBlob( self::BLOB, $bag );
	}

	public function touchAuth( string $provider, string $issuer, string $subject ): void {
		$key = self::key( $provider, $issuer, $subject );
		$bag = $this->store->blob( self::BLOB );
		if ( ! isset( $bag[ $key ] ) || ! is_array( $bag[ $key ] ) ) {
			return;
		}
		$bag[ $key ]['lastAuthAt'] = gmdate( 'c' );
		$this->store->writeBlob( self::BLOB, $bag );
	}

	public function unlink( string $provider, string $issuer, string $subject, int $userId ): bool {
		$key = self::key( $provider, $issuer, $subject );
		$bag = $this->store->blob( self::BLOB );
		$row = $bag[ $key ] ?? null;
		if ( ! is_array( $row ) || (int) ( $row['userId'] ?? 0 ) !== $userId ) {
			return false;
		}
		$bag[ $key ]['status']     = 'revoked';
		$bag[ $key ]['revokedAt']  = gmdate( 'c' );
		$this->store->writeBlob( self::BLOB, $bag );
		return true;
	}

	/** @return list<array<string,mixed>> */
	public function listForUser( int $userId ): array {
		$out = array();
		foreach ( $this->store->blob( self::BLOB ) as $row ) {
			if ( is_array( $row ) && (int) ( $row['userId'] ?? 0 ) === $userId && ( $row['status'] ?? '' ) === 'active' ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public static function key( string $provider, string $issuer, string $subject ): string {
		return hash( 'sha256', $provider . '|' . $issuer . '|' . $subject );
	}
}
