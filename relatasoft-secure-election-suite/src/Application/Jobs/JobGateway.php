<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Jobs;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobStore;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\KeygenJobService;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\RsvExportJobService;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\RsvImportJobService;

/**
 * Composition root for A4 job ports.
 */
final class JobGateway {

	private static ?self $instance = null;

	public function __construct(
		public readonly JobStore $store,
		public readonly KeygenJobService $keygen,
		public readonly RsvImportJobService $rsvImport,
		public readonly RsvExportJobService $rsvExport,
	) {}

	public static function boot(self $gateway): self {
		self::$instance = $gateway;
		return $gateway;
	}

	public static function get(): self {
		if (null === self::$instance) {
			throw new \RuntimeException('JobGateway is not booted.');
		}
		return self::$instance;
	}

	public static function isBooted(): bool {
		return null !== self::$instance;
	}

	/** @internal tests */
	public static function reset(): void {
		self::$instance = null;
	}
}
