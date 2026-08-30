<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies;

/**
 * Port: persisted signed-results metadata (A2 residual / M2 hardening).
 *
 * Binary attachments stay in Adapter #1; this port replaces get_option/update_option/delete_option.
 */
interface SignedResultsStore {

	/** @return array<string,mixed>|null */
	public function get( int $importId ): ?array;

	/** @param array<string,mixed> $meta */
	public function put( int $importId, array $meta ): void;

	public function delete( int $importId ): void;
}
