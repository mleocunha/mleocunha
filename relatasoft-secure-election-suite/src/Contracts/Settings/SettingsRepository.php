<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Settings;

interface SettingsRepository {
	/** @return array<string, mixed> */
	public function get(): array;

	/** @param array<string, mixed> $settings */
	public function save(array $settings): void;

	public function schemaVersion(): int;
}
