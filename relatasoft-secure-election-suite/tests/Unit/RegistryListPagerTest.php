<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\RegistryListPager;

/**
 * Paginação do Cadastro Eleitoral (domínio puro).
 */
final class RegistryListPagerTest extends TestCase {

	public function test_per_page_options_and_default(): void {
		$this->assertSame( array( 25, 50, 100, 200 ), RegistryListPager::PER_PAGE_OPTIONS );
		$this->assertSame( 25, RegistryListPager::DEFAULT_PER_PAGE );
		$this->assertSame( 25, RegistryListPager::normalizePerPage( 0 ) );
		$this->assertSame( 25, RegistryListPager::normalizePerPage( 99 ) );
		$this->assertSame( 50, RegistryListPager::normalizePerPage( 50 ) );
		$this->assertSame( 200, RegistryListPager::normalizePerPage( 200 ) );
	}

	public function test_total_pages_and_clamping(): void {
		$this->assertSame( 1, RegistryListPager::totalPages( 0, 25 ) );
		$this->assertSame( 1, RegistryListPager::totalPages( 25, 25 ) );
		$this->assertSame( 2, RegistryListPager::totalPages( 26, 25 ) );
		$this->assertSame( 4, RegistryListPager::totalPages( 100, 25 ) );

		$this->assertSame( 1, RegistryListPager::normalizePage( 0, 100, 25 ) );
		$this->assertSame( 4, RegistryListPager::normalizePage( 99, 100, 25 ) );
		$this->assertSame( 2, RegistryListPager::normalizePage( 2, 100, 25 ) );
	}

	public function test_offset_for_list_queries(): void {
		$this->assertSame( 0, RegistryListPager::offset( 1, 100, 25 ) );
		$this->assertSame( 25, RegistryListPager::offset( 2, 100, 25 ) );
		$this->assertSame( 75, RegistryListPager::offset( 4, 100, 25 ) );
		// Página pedida além do fim → última página.
		$this->assertSame( 75, RegistryListPager::offset( 99, 100, 25 ) );
	}

	public function test_query_keys_sanitized(): void {
		$this->assertSame( 'rses_p_subscriber', RegistryListPager::pageQueryKey( 'subscriber' ) );
		$this->assertSame( 'rses_pp_ve_auditor', RegistryListPager::perPageQueryKey( 've_auditor' ) );
		$this->assertSame( 'rses_p_editor', RegistryListPager::pageQueryKey( 'Editor!' ) );
	}
}
