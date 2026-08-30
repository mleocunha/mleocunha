<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;

/*
 * ModeLock vive em includes/ (fora do PSR-4 de src/) e faz
 * `defined('ABSPATH') || exit` no topo do ficheiro. Em PHPUnit sem WordPress
 * precisamos de stubar ABSPATH e carregar o ficheiro à mão.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/rses-tests/' );
}

require_once dirname( __DIR__, 2 ) . '/includes/Bootstrap/ModeLock.php';

/**
 * C1 — Isolamento E3: ModeLock (Adapter #1) alinhado a SiteModes (domínio).
 *
 * Garante que a fachada WP não inventa slugs/rótulos próprios e que só
 * existem exactamente três papéis válidos (sem “all-in-one”).
 */
final class ModeIsolationTest extends TestCase {

	/**
	 * Os slugs e rótulos expostos por ModeLock são exactamente os de SiteModes.
	 * Qualquer drift (modo extra ou label EN) quebra este teste.
	 */
	public function test_mode_lock_slugs_match_site_modes(): void {
		$this->assertSame( SiteModes::all(), array_keys( ModeLock::rses_get_valid_modes() ) );
		foreach ( SiteModes::all() as $slug ) {
			$this->assertSame( SiteModes::label( $slug ), ModeLock::rses_get_mode_label( $slug ) );
		}
	}

	/**
	 * Constantes legadas RSES_MODE_* são aliases estáveis de SiteModes
	 * (código antigo em includes/ continua a compilar sem redefinir o domínio).
	 */
	public function test_constants_alias_site_modes(): void {
		$this->assertSame( SiteModes::KEY_AUTHORITY, ModeLock::RSES_MODE_KEY_AUTHORITY );
		$this->assertSame( SiteModes::VOTING, ModeLock::RSES_MODE_VOTING );
		$this->assertSame( SiteModes::TALLYING, ModeLock::RSES_MODE_TALLYING );
	}

	/**
	 * Conjunto fechado: 3 papéis; valores inventados / vazios são inválidos.
	 */
	public function test_exactly_three_isolated_roles(): void {
		$this->assertCount( 3, SiteModes::all() );
		$this->assertFalse( SiteModes::isValid( 'all_in_one' ) );
		$this->assertFalse( SiteModes::isValid( '' ) );
	}
}
