<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Settings;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Settings\SettingsRepository;
use RelataSoft\SecureElectionSuite\Painel\Domain\Settings\SettingsSchema;

final class WordPressSettingsRepository implements SettingsRepository {

	public function get(): array {
		$raw = get_option( SettingsSchema::OPTION_KEY, array() );
		return is_array( $raw ) ? $raw : array();
	}

	public function save(array $settings): void {
		update_option( SettingsSchema::OPTION_KEY, $settings, false );
	}

	public function schemaVersion(): int {
		$cfg = $this->get();
		return (int) ( $cfg['schema_version'] ?? 0 );
	}
}
