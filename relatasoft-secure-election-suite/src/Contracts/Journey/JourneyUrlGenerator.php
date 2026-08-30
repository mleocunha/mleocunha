<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Journey;

/**
 * Port: build absolute URLs for elector journey steps (A5).
 *
 * Domain and redirects must not call get_permalink / page IDs.
 */
interface JourneyUrlGenerator {

	/** Site-relative base segment, e.g. "voto". */
	public function basePath(): string;

	/** Relative path for a step, e.g. "voto/cabina". */
	public function pathFor( string $step ): string;

	/**
	 * Absolute URL for a journey step.
	 *
	 * @param array<string,scalar> $query Optional query args (election_id, round_id, …).
	 */
	public function urlFor( string $step, array $query = array() ): string;
}
