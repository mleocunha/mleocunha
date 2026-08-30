<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Security;

/**
 * Port: opaque key material for at-rest share/private-key encryption (A3).
 *
 * Adapter #1 may still derive from host secrets *inside* the adapter.
 * Domain code must never read AUTH_KEY / salts directly.
 */
interface SecretKeyProvider {
	/** Raw binary key suitable for AES-256 (32 bytes). */
	public function shareStorageKey(): string;
}
