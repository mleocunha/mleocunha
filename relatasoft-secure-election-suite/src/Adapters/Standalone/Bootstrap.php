<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone;

use RelataSoft\SecureElectionSuite\Painel\Application\Standalone\ThreeNodePilot;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;

/**
 * Adapter #2 entry — standalone node(s) without the legacy host.
 */
final class Bootstrap {

	/**
	 * Create one process-bound node and optionally register gateways as singletons
	 * for that process (CLI / single-node deploy).
	 */
	public static function bootNode( string $mode, string $dataDir, string $clienteId = '', bool $asSingletons = true ): NodeRuntime {
		$node = NodeRuntime::create( $mode, $dataDir, $clienteId !== '' ? $clienteId : 'piloto' );

		if ( $asSingletons ) {
			\RelataSoft\SecureElectionSuite\Painel\Application\Persistence\PersistenceGateway::boot( $node->persistence );
			\RelataSoft\SecureElectionSuite\Painel\Application\Identity\IdentityGateway::boot( $node->identity );
			\RelataSoft\SecureElectionSuite\Painel\Application\Jobs\JobGateway::boot( $node->jobs );
			\RelataSoft\SecureElectionSuite\Painel\Application\Journey\JourneyGateway::boot( $node->journey );
		}

		return $node;
	}

	/**
	 * Full three-node pilot workspace (tests / ops rehearsal). Never one shared runtime.
	 */
	public static function bootPilotWorkspace( string $root, string $clienteId = 'piloto', int $bits = 512 ): ThreeNodePilot {
		return ThreeNodePilot::createWorkspace( $root, $clienteId, $bits );
	}

	/**
	 * @return list<string>
	 */
	public static function supportedModes(): array {
		return SiteModes::all();
	}
}
