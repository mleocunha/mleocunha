<?php
declare(strict_types=1);

/**
 * A4 / M4 job ports — InMemory (no sítio / admin-ajax).
 *
 * @package RelataSoft\SecureElectionSuite\Tests\Unit\Jobs
 */

namespace RelataSoft\SecureElectionSuite\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Jobs\WordPressJobStore;
use RelataSoft\SecureElectionSuite\Painel\Application\Jobs\JobGateway;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobResult;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobSlots;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Jobs\InMemoryJobStore;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Jobs\InMemoryKeygenJobService;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Jobs\InMemoryRsvExportJobService;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Jobs\InMemoryRsvImportJobService;

final class JobPortsTest extends TestCase {

	private JobGateway $gw;
	private InMemoryJobStore $store;

	protected function setUp(): void {
		JobGateway::reset();
		$this->store = new InMemoryJobStore();
		$this->gw    = JobGateway::boot(
			new JobGateway(
				$this->store,
				new InMemoryKeygenJobService( $this->store ),
				new InMemoryRsvImportJobService( $this->store, 7 ),
				new InMemoryRsvExportJobService( $this->store, 7 ),
			)
		);
	}

	protected function tearDown(): void {
		JobGateway::reset();
	}

	public function test_job_store_slots(): void {
		$this->gw->store->put( JobSlots::KEYGEN, array( 'stage' => 'safe_prime' ) );
		$this->assertSame( 'safe_prime', $this->gw->store->get( JobSlots::KEYGEN )['stage'] ?? null );
		$this->gw->store->delete( JobSlots::KEYGEN );
		$this->assertNull( $this->gw->store->get( JobSlots::KEYGEN ) );
		$this->assertSame( 'rsv_import:7', JobSlots::rsvImport( 7 ) );
		$this->assertSame( 'rsv_export:7', JobSlots::rsvExport( 7 ) );
	}

	public function test_wordpress_option_key_mapping_is_pure(): void {
		$this->assertSame( 'rses_keygen_job', WordPressJobStore::optionKeyForSlot( JobSlots::KEYGEN ) );
		$this->assertSame( 'rses_electoral_roll_job_3', WordPressJobStore::optionKeyForSlot( JobSlots::rsvImport( 3 ) ) );
		$this->assertSame( 'rses_electoral_roll_export_job_9', WordPressJobStore::optionKeyForSlot( JobSlots::rsvExport( 9 ) ) );
	}

	public function test_keygen_start_tick_complete_and_cancel(): void {
		$status = $this->gw->keygen->start( array( 'bits' => 512, 'label' => 't' ) );
		$this->assertTrue( $status['active'] );
		$this->assertTrue( $this->gw->keygen->hasActive() );

		while ( $this->gw->keygen->hasActive() ) {
			$status = $this->gw->keygen->tick();
		}
		$this->assertSame( 'complete', $status['stage'] );
		$this->assertSame( 1, $status['key_id'] );

		$this->gw->keygen->start( array( 'bits' => 512 ) );
		$cancelled = $this->gw->keygen->cancel();
		$this->assertSame( 'cancelled', $cancelled['stage'] );
		$this->assertFalse( $cancelled['active'] );
	}

	public function test_rsv_import_pipeline_cancel_and_ingest(): void {
		$s = $this->gw->rsvImport->createReceiving( 'cadastro.rsv', 2, 100, false );
		$this->assertSame( 'receiving', $s['stage'] );
		$this->assertFalse( JobResult::isFailure( $s ) );
		$this->gw->rsvImport->appendChunk( '/tmp/a', 0 );
		$s = $this->gw->rsvImport->appendChunk( '/tmp/b', 1 );
		$this->assertSame( 'ready', $s['stage'] );
		$this->gw->rsvImport->begin();
		$s = $this->gw->rsvImport->tick();
		$this->assertSame( 'complete', $s['stage'] );
		$this->assertFalse( $this->gw->rsvImport->hasActive() );

		$idle = $this->gw->rsvImport->status();
		$this->assertFalse( $idle['active'] );

		$ingested = $this->gw->rsvImport->ingestFullUpload( '/tmp/full', 'full.rsv', true );
		$this->assertSame( 'receiving', $ingested['stage'] );
		$cancelled = $this->gw->rsvImport->cancel();
		$this->assertSame( 'cancelled', $cancelled['stage'] );
		$this->assertFalse( $this->gw->rsvImport->hasActive() );

		$fail = $this->gw->rsvImport->appendChunk( '/tmp/x', 0 );
		$this->assertTrue( JobResult::isFailure( $fail ) );
	}

	public function test_rsv_export_pipeline_download_and_cancel(): void {
		$s = $this->gw->rsvExport->start( 'subscriber', 100 );
		$this->assertTrue( $s['active'] );
		$this->assertTrue( $this->gw->rsvExport->hasActive() );
		$s = $this->gw->rsvExport->tick();
		$this->assertSame( 'complete', $s['stage'] );
		$this->assertNotNull( $this->gw->rsvExport->downloadPath() );
		$this->assertSame( 'cadastro-teste.rsv', $this->gw->rsvExport->downloadFilename() );
		$this->assertFalse( $this->gw->rsvExport->hasActive() );

		$this->gw->rsvExport->start( 'editor', 10 );
		$cancelled = $this->gw->rsvExport->cancel();
		$this->assertSame( 'cancelled', $cancelled['stage'] );
		$this->assertNull( $this->gw->rsvExport->downloadPath() );
	}

	public function test_job_result_helpers(): void {
		$fail = JobResult::fail( 'x', 'boom' );
		$this->assertTrue( JobResult::isFailure( $fail ) );
		$this->assertSame( 'boom', JobResult::message( $fail ) );
		$this->assertSame( 'x', JobResult::code( $fail ) );
		$this->assertFalse( JobResult::isFailure( array( 'stage' => 'complete', 'active' => false ) ) );
	}

	public function test_gateway_requires_boot(): void {
		JobGateway::reset();
		$this->expectException( \RuntimeException::class );
		JobGateway::get();
	}
}
