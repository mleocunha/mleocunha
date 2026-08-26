<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Settings;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Platform\Logger;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Settings\SettingsRepository;
use RelataSoft\SecureElectionSuite\Painel\Domain\Settings\SettingsSchema;

final class SettingsService {

	public function __construct(
		private readonly SettingsRepository $repository,
		private readonly Logger $logger,
	) {}

	/** @return array<string, mixed> */
	public function get(): array {
		return array_merge( SettingsSchema::defaults(), $this->repository->get() );
	}

	/** @param array<string, mixed> $patch */
	public function update(array $patch): array {
		$current = $this->get();
		$next    = array_merge( $current, $this->sanitize( $patch ) );
		$next['schema_version'] = SettingsSchema::VERSION;
		$this->repository->save( $next );
		$this->logger->info( 'painel.settings.updated', array( 'schema_version' => SettingsSchema::VERSION ) );
		return $next;
	}

	public function migrate(): void {
		$current = $this->repository->get();
		$version = (int) ( $current['schema_version'] ?? 0 );
		if ( $version < 1 ) {
			SettingsMigrationV1::apply( $this->repository );
			$this->logger->info( 'painel.settings.migrated', array( 'to' => 1 ) );
		}
	}

	/**
	 * @param array<string, mixed> $patch
	 * @return array<string, mixed>
	 */
	private function sanitize(array $patch): array {
		$out = array();
		$bools = array( 'shell_enabled', 'hide_wp_menus', 'hide_wp_admin_bar', 'redirect_dashboard', 'login_branding', 'dark_mode' );
		foreach ( $bools as $key ) {
			if ( array_key_exists( $key, $patch ) ) {
				$out[ $key ] = (bool) $patch[ $key ];
			}
		}
		foreach ( array( 'product_name', 'panel_name', 'primary_color', 'ink_color', 'font_family' ) as $key ) {
			if ( isset( $patch[ $key ] ) && is_string( $patch[ $key ] ) ) {
				$out[ $key ] = trim( $patch[ $key ] );
			}
		}
		return $out;
	}
}
