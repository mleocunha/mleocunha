<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Platform\UrlMaskConfig;

/**
 * C2 / R4 — política de 404 da máscara de URL sem boot do sítio.
 *
 * Espelha o smoke `bin/ve-url-mask-smoke` em asserções PHPUnit, cobrindo
 * login/admin clássicos, ecrãs retirados sob `/painel`, loaders de assets
 * e o comportamento “antes do gateway” (stubs ainda não prontos).
 */
final class UrlMask404PolicyTest extends TestCase {

	/**
	 * Com stubs prontos, wp-login e /wp-admin clássicos respondem como inexistentes.
	 */
	public function test_classic_login_and_admin_404_when_stubs_ready(): void {
		$this->assertSame(
			'not_found',
			UrlMaskConfig::publicAccessDecision( '/wp-login.php', true, true )
		);
		$this->assertSame(
			'not_found',
			UrlMaskConfig::publicAccessDecision( '/wp-admin/admin.php', true, true )
		);
		$this->assertSame(
			'not_found',
			UrlMaskConfig::publicAccessDecision( '/wp-admin/', true, true )
		);
	}

	/**
	 * Entrada do Painel (`admin.php`) permitida; plugins/temas clássicos sob
	 * `/painel` continuam 404 (não reaparecem pela fachada).
	 *
	 * Nota: `publicAccessDecision` olha o path; query string em admin.php
	 * não altera o basename (`admin.php` não está na lista retired).
	 */
	public function test_painel_entry_allowed_retired_screens_404(): void {
		$this->assertSame(
			'allow',
			UrlMaskConfig::publicAccessDecision( '/painel/admin.php?page=rses-dashboard', true, true )
		);
		$this->assertSame(
			'not_found',
			UrlMaskConfig::publicAccessDecision( '/painel/plugins.php', true, true )
		);
		$this->assertSame(
			'not_found',
			UrlMaskConfig::publicAccessDecision( '/painel/themes.php', true, true )
		);
	}

	/**
	 * Loaders e CSS estáticos sob /wp-admin mantêm-se acessíveis (Painel precisa deles).
	 */
	public function test_asset_loaders_still_allowed_under_wp_admin(): void {
		$this->assertSame(
			'allow',
			UrlMaskConfig::publicAccessDecision( '/wp-admin/load-styles.php', true, true )
		);
		$this->assertSame(
			'allow',
			UrlMaskConfig::publicAccessDecision( '/wp-admin/css/dashicons.css', true, true )
		);
	}

	/**
	 * Antes do gateway/stub, a política não força 404 — o motor clássico
	 * ainda é a superfície real (instalação a meio da máscara).
	 */
	public function test_before_gateway_classic_admin_not_forced_404_by_policy(): void {
		$this->assertSame(
			'allow',
			UrlMaskConfig::publicAccessDecision( '/wp-admin/admin.php', false, false )
		);
		$this->assertSame(
			'allow',
			UrlMaskConfig::publicAccessDecision( '/wp-login.php', false, false )
		);
	}
}
