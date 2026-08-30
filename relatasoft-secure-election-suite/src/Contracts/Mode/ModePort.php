<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Mode;

/**
 * Port: operation mode of one isolated site node (A6).
 *
 * Adapter #1 may map this to host options; Adapter #2 locks from env/data-dir.
 * A process must never switch between the three E3 roles at runtime after lock.
 */
interface ModePort {

	public function getMode(): string;

	public function hasMode(): bool;

	public function isLocked(): bool;

	public function isMode( string $mode ): bool;

	/**
	 * Set and permanently lock the mode for this node.
	 *
	 * @throws \RuntimeException If already locked to a different mode, or mode invalid.
	 */
	public function lock( string $mode ): void;
}
