<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Persistence;

/**
 * Shared helpers for in-memory A2 stores (tests).
 */
trait InMemoryStoreTrait {

	/** @var array<int,array<string,mixed>> */
	private array $rows = array();

	private int $autoId = 1;

	/**
	 * @param array<string,mixed> $data
	 */
	private function insertRow( array $data ): int {
		$id               = $this->autoId++;
		$data['id']       = $id;
		$this->rows[ $id ] = $data;
		return $id;
	}

	/** @return array<string,mixed>|null */
	private function findRow( int $id ): ?array {
		return $this->rows[ $id ] ?? null;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function allRows(): array {
		return array_values( $this->rows );
	}
}
