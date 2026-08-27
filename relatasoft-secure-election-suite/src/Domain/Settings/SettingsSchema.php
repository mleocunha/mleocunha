<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Settings;

/**
 * Versioned Painel settings schema.
 */
final class SettingsSchema {

	public const VERSION = 1;
	public const OPTION_KEY = 've_painel_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'schema_version'              => self::VERSION,
			'shell_enabled'               => true,
			'hide_wp_menus'               => true,
			'hide_wp_admin_bar'           => true,
			'redirect_dashboard'          => true,
			'login_branding'              => true,
			'mask_platform_urls'          => true,
			'admin_path'                  => 'painel',
			'login_path'                  => 'id.php',
			'hide_platform_fingerprint'   => true,
			'product_name'                => 'Voto Eletrônico by RelataSoft',
			'panel_name'                  => 'Painel de Controle Eleitoral',
			'primary_color'               => '#0c7c9c',
			'ink_color'                   => '#1c2a32',
			'font_family'                 => 'Open Sans',
			'dark_mode'                   => false,
		);
	}
}
