<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\ModePort;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;

/**
 * Adapter #2: mode locked from constructor / env (immutable after lock).
 */
final class EnvModeLock implements ModePort {

	private string $mode = '';
	private bool $locked = false;

	public function getMode(): string {
		return $this->mode;
	}

	public function hasMode(): bool {
		return SiteModes::isValid( $this->mode );
	}

	public function isLocked(): bool {
		return $this->locked;
	}

	public function isMode( string $mode ): bool {
		return $this->mode === $mode;
	}

	public function lock( string $mode ): void {
		if ( ! SiteModes::isValid( $mode ) ) {
			throw new \InvalidArgumentException( 'Invalid site mode: ' . $mode );
		}
		if ( $this->locked && $this->mode !== $mode ) {
			throw new \RuntimeException(
				sprintf( 'Node already locked to %s; cannot switch to %s.', $this->mode, $mode )
			);
		}
		$this->mode   = $mode;
		$this->locked = true;
	}

	/**
	 * Factory from RSES_MODE / VE_MODE environment (CLI process = one sítio).
	 */
	public static function fromEnvironment(): self {
		$raw = getenv( 'RSES_MODE' );
		if ( false === $raw || '' === $raw ) {
			$raw = getenv( 'VE_MODE' );
		}
		$mode = is_string( $raw ) ? trim( $raw ) : '';
		$lock = new self();
		if ( '' !== $mode ) {
			$lock->lock( $mode );
		}
		return $lock;
	}
}
