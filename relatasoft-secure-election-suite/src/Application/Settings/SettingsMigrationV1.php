<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Settings;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Settings\SettingsRepository;
use RelataSoft\SecureElectionSuite\Painel\Domain\Settings\SettingsSchema;

final class SettingsMigrationV1 {

	public static function apply(SettingsRepository $repository): void {
		$merged = array_merge( SettingsSchema::defaults(), $repository->get() );
		$merged['schema_version'] = 1;
		$repository->save( $merged );
	}
}
