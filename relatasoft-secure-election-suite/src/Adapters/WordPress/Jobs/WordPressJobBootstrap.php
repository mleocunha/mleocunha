<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Jobs;

use RelataSoft\SecureElectionSuite\Painel\Application\Jobs\JobGateway;
use RelataSoft\SecureElectionSuite\Painel\Application\Jobs\LegacyKeygenJobService;
use RelataSoft\SecureElectionSuite\Painel\Application\Jobs\LegacyRsvExportJobService;
use RelataSoft\SecureElectionSuite\Painel\Application\Jobs\LegacyRsvImportJobService;

/**
 * Boots Adapter #1 job ports into {@see JobGateway}.
 */
final class WordPressJobBootstrap {

	public static function boot(): JobGateway {
		$store = new WordPressJobStore();
		return JobGateway::boot(
			new JobGateway(
				$store,
				new LegacyKeygenJobService(),
				new LegacyRsvImportJobService(),
				new LegacyRsvExportJobService(),
			)
		);
	}
}
