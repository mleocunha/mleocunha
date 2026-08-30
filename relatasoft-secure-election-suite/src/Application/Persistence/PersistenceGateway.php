<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Persistence;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Audit\AuditLogRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Elections\ElectionRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Keys\KeyRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Keys\ShareRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\CertificationRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\EncryptedTallyRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\OfficialShareSubmissionRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\SignedResultsStore;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\TallyImportRepository;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Votes\EncryptedVoteRepository;

/**
 * Composition root for A2 persistence ports.
 *
 * Legacy includes call {@see self::get()} after Adapter #1 boots the gateway.
 */
final class PersistenceGateway {

	private static ?self $instance = null;

	public function __construct(
		public readonly KeyRepository $keys,
		public readonly ShareRepository $shares,
		public readonly ElectionRepository $elections,
		public readonly EncryptedVoteRepository $votes,
		public readonly EncryptedTallyRepository $encryptedTallies,
		public readonly TallyImportRepository $tallyImports,
		public readonly OfficialShareSubmissionRepository $shareSubmissions,
		public readonly CertificationRepository $certifications,
		public readonly AuditLogRepository $auditLog,
		public readonly SignedResultsStore $signedResults,
	) {}

	public static function boot( self $gateway ): self {
		self::$instance = $gateway;
		return $gateway;
	}

	public static function get(): self {
		if ( null === self::$instance ) {
			throw new \RuntimeException( 'PersistenceGateway is not booted.' );
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
