<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence;

use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Audit\WordPressAuditLogRepository;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Elections\WordPressElectionRepository;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Keys\WordPressKeyRepository;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Keys\WordPressShareRepository;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Tallies\WordPressCertificationRepository;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Tallies\WordPressEncryptedTallyRepository;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Tallies\WordPressOfficialShareSubmissionRepository;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Tallies\WordPressSignedResultsStore;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Tallies\WordPressTallyImportRepository;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Votes\WordPressEncryptedVoteRepository;
use RelataSoft\SecureElectionSuite\Painel\Application\Persistence\PersistenceGateway;

/**
 * Boots Adapter #1 persistence ports into {@see PersistenceGateway}.
 */
final class WordPressPersistenceBootstrap {

	public static function boot(): PersistenceGateway {
		return PersistenceGateway::boot(
			new PersistenceGateway(
				new WordPressKeyRepository(),
				new WordPressShareRepository(),
				new WordPressElectionRepository(),
				new WordPressEncryptedVoteRepository(),
				new WordPressEncryptedTallyRepository(),
				new WordPressTallyImportRepository(),
				new WordPressOfficialShareSubmissionRepository(),
				new WordPressCertificationRepository(),
				new WordPressAuditLogRepository(),
				new WordPressSignedResultsStore(),
			)
		);
	}
}
