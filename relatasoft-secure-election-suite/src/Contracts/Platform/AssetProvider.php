<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Platform;

interface AssetProvider {
	public function enqueueAdminShell(): void;

	public function enqueueLoginBranding(): void;
}
