<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Application\Settings\SettingsMigrationV1;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Settings\SettingsRepository;
use RelataSoft\SecureElectionSuite\Painel\Domain\Settings\SettingsSchema;

final class InMemorySettingsRepository implements SettingsRepository {
	/** @var array<string,mixed> */
	private array $data = array();
	public function get(): array { return $this->data; }
	public function save(array $settings): void { $this->data = $settings; }
	public function schemaVersion(): int { return (int) ( $this->data['schema_version'] ?? 0 ); }
}

final class SettingsMigrationTest extends TestCase {
	public function test_migration_v1_writes_schema(): void {
		$repo = new InMemorySettingsRepository();
		SettingsMigrationV1::apply( $repo );
		$cfg = $repo->get();
		$this->assertSame( 1, $cfg['schema_version'] );
		$this->assertSame( SettingsSchema::defaults()['panel_name'], $cfg['panel_name'] );
	}
}
