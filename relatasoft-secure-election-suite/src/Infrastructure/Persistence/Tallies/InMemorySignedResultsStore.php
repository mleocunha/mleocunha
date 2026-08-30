<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence\Tallies;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\SignedResultsStore;

final class InMemorySignedResultsStore implements SignedResultsStore {

	/** @var array<int,array<string,mixed>> */
	private array $rows = array();

	public function get( int $importId ): ?array {
		return $this->rows[ $importId ] ?? null;
	}

	public function put( int $importId, array $meta ): void {
		$this->rows[ $importId ] = $meta;
	}

	public function delete( int $importId ): void {
		unset( $this->rows[ $importId ] );
	}
}
