<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Security;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Security\SecretKeyProvider;

final class InMemorySecretKeyProvider implements SecretKeyProvider {

	public function __construct(private readonly string $material = 'test-share-key-material') {}

	public function shareStorageKey(): string {
		return hash('sha256', $this->material . 'rses_share_encryption', true);
	}
}
