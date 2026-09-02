<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone;

use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Identity\FileJsonUserStore;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Jobs\JsonFileJobStore;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Jobs\StandaloneKeygenJobService;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Persistence\StandalonePersistenceFactory;
use RelataSoft\SecureElectionSuite\Painel\Application\Identity\IdentityGateway;
use RelataSoft\SecureElectionSuite\Painel\Application\Jobs\JobGateway;
use RelataSoft\SecureElectionSuite\Painel\Application\Journey\JourneyGateway;
use RelataSoft\SecureElectionSuite\Painel\Application\Persistence\PersistenceGateway;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\ModePort;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserDirectory;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserProvider;
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
 * One isolated site node — own gateways, own mode, no shared store across nodes.
 *
 * Persistence + identity + jobs JSON under dataDir when durable (default).
 */
final class NodeRuntime {

	/**
	 * @param UserProvider&UserDirectory $users
	 */
	public function __construct(
		public readonly string $dataDir,
		public readonly ModePort $mode,
		public readonly PersistenceGateway $persistence,
		public readonly IdentityGateway $identity,
		public readonly JobGateway $jobs,
		public readonly JourneyGateway $journey,
		public readonly UserProvider&UserDirectory $users,
		public readonly string $clienteId = '',
		public readonly bool $durablePersistence = true,
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

	public function persistencePath(): string {
		return rtrim( $this->dataDir, '/\\' ) . DIRECTORY_SEPARATOR . 'persistence.json';
	}

	public function identityPath(): string {
		return rtrim( $this->dataDir, '/\\' ) . DIRECTORY_SEPARATOR . 'identity.json';
	}

	/**
	 * Pasta de courier **local a este nó** (nunca partilhada com outros VE_DATA).
	 * Material entre sítios move-se por cópia manual / canal auditável para o courier do destino.
	 */
	public function courierDirectory(): string {
		return rtrim( $this->dataDir, '/\\' ) . DIRECTORY_SEPARATOR . 'courier';
	}

	/**
	 * Create a fresh isolated node for the given E3 mode.
	 *
	 * @param bool   $durable When true (default), Persistence + Identity use JSON under dataDir.
	 * @param string $publicBase Base URL for journey links (ex.: http://10.42.0.1:8888).
	 */
	public static function create(
		string $mode,
		string $dataDir,
		string $clienteId = 'piloto',
		bool $durable = true,
		string $publicBase = ''
	): self {
		if ( ! SiteModes::isValid( $mode ) ) {
			throw new \InvalidArgumentException( 'Invalid mode: ' . $mode );
		}
		if ( ! is_dir( $dataDir ) && ! mkdir( $dataDir, 0700, true ) && ! is_dir( $dataDir ) ) {
			throw new \RuntimeException( 'Cannot create node data dir: ' . $dataDir );
		}

		$modePort = new EnvModeLock();
		$modePort->lock( $mode );

		/** @var UserProvider&UserDirectory $users */
		$users = $durable
			? FileJsonUserStore::open( rtrim( $dataDir, '/\\' ) . DIRECTORY_SEPARATOR . 'identity.json' )
			: new InMemoryUserStore();

		$jobStore = $durable
			? new JsonFileJobStore( rtrim( $dataDir, '/\\' ) . DIRECTORY_SEPARATOR . 'jobs.json' )
			: new InMemoryJobStore();

		$persistence = $durable
			? StandalonePersistenceFactory::create( rtrim( $dataDir, '/\\' ) . DIRECTORY_SEPARATOR . 'persistence.json' )
			: new PersistenceGateway(
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

		$courierDir = rtrim( $dataDir, '/\\' ) . DIRECTORY_SEPARATOR . 'courier';
		$keygenService = $durable
			? new StandaloneKeygenJobService( $jobStore, $persistence, $courierDir, $clienteId )
			: new InMemoryKeygenJobService( $jobStore );

		$jobs = new JobGateway(
			$jobStore,
			$keygenService,
			new InMemoryRsvImportJobService( $jobStore, 1 ),
			new InMemoryRsvExportJobService( $jobStore, 1 ),
		);

		$base = $publicBase !== '' ? rtrim( $publicBase, '/' ) : ( 'https://' . $mode . '.piloto.local' );
		$journey = new JourneyGateway(
			new InMemoryJourneyUrlGenerator( $base ),
			new InMemoryJourneyRouteResolver(),
			new InMemoryJourneyPresenter(),
		);

		file_put_contents(
			rtrim( $dataDir, '/\\' ) . DIRECTORY_SEPARATOR . 'node.json',
			json_encode(
				array(
					'mode'             => $mode,
					'cliente_id'       => $clienteId,
					'adapter'          => 'standalone',
					'persistence'      => $durable ? 'json' : 'memory',
					'persistence_file' => $durable ? 'persistence.json' : null,
					'identity_file'    => $durable ? 'identity.json' : null,
					'created_at'       => gmdate( 'c' ),
				),
				JSON_PRETTY_PRINT
			) . "\n"
		);

		return new self( $dataDir, $modePort, $persistence, $identity, $jobs, $journey, $users, $clienteId, $durable );
	}
}
