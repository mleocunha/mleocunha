<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone;

use RelataSoft\SecureElectionSuite\Painel\Application\Identity\IdentityGateway;
use RelataSoft\SecureElectionSuite\Painel\Application\Jobs\JobGateway;
use RelataSoft\SecureElectionSuite\Painel\Application\Journey\JourneyGateway;
use RelataSoft\SecureElectionSuite\Painel\Application\Persistence\PersistenceGateway;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\ModePort;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Security\InMemorySecretKeyProvider;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Session\InMemorySessionPort;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\User\InMemoryCapabilityResolver;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\User\InMemoryUserStore;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Jobs\InMemoryJobStore;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Jobs\InMemoryKeygenJobService;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Jobs\InMemoryRsvExportJobService;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Jobs\InMemoryRsvImportJobService;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Journey\InMemoryJourneyPresenter;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Journey\InMemoryJourneyRouteResolver;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Journey\InMemoryJourneyUrlGenerator;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Audit\InMemoryAuditLogRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Elections\InMemoryElectionRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Keys\InMemoryKeyRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Keys\InMemoryShareRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies\InMemoryCertificationRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies\InMemoryEncryptedTallyRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies\InMemoryOfficialShareSubmissionRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies\InMemorySignedResultsStore;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies\InMemoryTallyImportRepository;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Votes\InMemoryEncryptedVoteRepository;

/**
 * One isolated site node (Adapter #2) — own gateways, own mode, no shared DB.
 *
 * Intentionally does not call Gateway::boot() singletons so three nodes can
 * coexist in one PHPUnit process (production = three separate processes).
 */
final class NodeRuntime {

	public function __construct(
		public readonly string $dataDir,
		public readonly ModePort $mode,
		public readonly PersistenceGateway $persistence,
		public readonly IdentityGateway $identity,
		public readonly JobGateway $jobs,
		public readonly JourneyGateway $journey,
		public readonly string $clienteId = '',
	) {}

	public function requireMode( string $expected ): void {
		if ( ! $this->mode->isMode( $expected ) ) {
			throw new \RuntimeException(
				sprintf(
					'Node mode is %s; operation requires %s.',
					$this->mode->getMode() ?: '(unset)',
					$expected
				)
			);
		}
	}

	/**
	 * Create a fresh isolated node for the given E3 mode.
	 */
	public static function create( string $mode, string $dataDir, string $clienteId = 'piloto' ): self {
		if ( ! SiteModes::isValid( $mode ) ) {
			throw new \InvalidArgumentException( 'Invalid mode: ' . $mode );
		}
		if ( ! is_dir( $dataDir ) && ! mkdir( $dataDir, 0700, true ) && ! is_dir( $dataDir ) ) {
			throw new \RuntimeException( 'Cannot create node data dir: ' . $dataDir );
		}

		$modePort = new EnvModeLock();
		$modePort->lock( $mode );

		$users = new InMemoryUserStore();
		$jobStore = new InMemoryJobStore();

		$persistence = new PersistenceGateway(
			new InMemoryKeyRepository(),
			new InMemoryShareRepository(),
			new InMemoryElectionRepository(),
			new InMemoryEncryptedVoteRepository(),
			new InMemoryEncryptedTallyRepository(),
			new InMemoryTallyImportRepository(),
			new InMemoryOfficialShareSubmissionRepository(),
			new InMemoryCertificationRepository(),
			new InMemoryAuditLogRepository(),
			new InMemorySignedResultsStore(),
		);

		$identity = new IdentityGateway(
			$users,
			$users,
			new InMemoryCapabilityResolver( $users, $users ),
			new InMemorySessionPort( $users ),
			new InMemorySecretKeyProvider( 'standalone-piloto-' . $mode . '-' . $clienteId ),
		);

		$jobs = new JobGateway(
			$jobStore,
			new InMemoryKeygenJobService( $jobStore ),
			new InMemoryRsvImportJobService( $jobStore, 1 ),
			new InMemoryRsvExportJobService( $jobStore, 1 ),
		);

		$journey = new JourneyGateway(
			new InMemoryJourneyUrlGenerator( 'https://' . $mode . '.piloto.local' ),
			new InMemoryJourneyRouteResolver(),
			new InMemoryJourneyPresenter(),
		);

		file_put_contents(
			rtrim( $dataDir, '/\\' ) . DIRECTORY_SEPARATOR . 'node.json',
			json_encode(
				array(
					'mode'       => $mode,
					'cliente_id' => $clienteId,
					'adapter'    => 'standalone',
					'created_at' => gmdate( 'c' ),
				),
				JSON_PRETTY_PRINT
			) . "\n"
		);

		return new self( $dataDir, $modePort, $persistence, $identity, $jobs, $journey, $clienteId );
	}
}
