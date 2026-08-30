<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Branding;

use RelataSoft\SecureElectionSuite\Painel\Application\Settings\SettingsService;

final class LoginBrandingService {

	public function __construct(
		private readonly SettingsService $settings,
	) {}

	public function isEnabled(): bool {
		$cfg = $this->settings->get();
		return (bool) ( $cfg['login_branding'] ?? true );
	}

	public function productName(): string {
		$cfg = $this->settings->get();
		return (string) ( $cfg['product_name'] ?? 'Voto Eletrônico by RelataSoft' );
	}

	public function panelName(): string {
		$cfg = $this->settings->get();
		return (string) ( $cfg['panel_name'] ?? 'Painel de Controle Eleitoral' );
	}
}
