<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Jobs;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobStore;

/**
 * Job store durável com ficheiro JSON + flock (worker e HTTP partilham o mesmo estado).
 */
final class JsonFileJobStore implements JobStore {

	public function __construct( private readonly string $path ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Cannot create jobs dir: ' . $dir );
		}
		if ( ! is_file( $path ) ) {
			$this->writeAll( array() );
		}
		@chmod( $path, 0600 );
	}

	public function get( string $slot ): ?array {
		$data = $this->withLock(
			false,
			function ( array $all ) use ( $slot ): array {
				return array( 'result' => $all[ $slot ] ?? null );
			}
		);
		$job = $data['result'] ?? null;
		return is_array( $job ) ? $job : null;
	}

	public function put( string $slot, array $job ): void {
		$this->withLock(
			true,
			function ( array $all ) use ( $slot, $job ): array {
				$all[ $slot ] = $job;
				return array( 'all' => $all );
			}
		);
	}

	public function delete( string $slot ): void {
		$this->withLock(
			true,
			function ( array $all ) use ( $slot ): array {
				unset( $all[ $slot ] );
				return array( 'all' => $all );
			}
		);
	}

	/**
	 * @param callable(array<string,mixed>):array<string,mixed> $fn
	 * @return array<string,mixed>
	 */
	private function withLock( bool $exclusive, callable $fn ): array {
		$fh = fopen( $this->path, 'c+' );
		if ( false === $fh ) {
			throw new \RuntimeException( 'Cannot open job store: ' . $this->path );
		}
		try {
			if ( ! flock( $fh, $exclusive ? LOCK_EX : LOCK_SH ) ) {
				throw new \RuntimeException( 'Cannot lock job store: ' . $this->path );
			}
			rewind( $fh );
			$raw  = stream_get_contents( $fh );
			$data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			$result = $fn( $data );
			if ( isset( $result['all'] ) && is_array( $result['all'] ) ) {
				$json = json_encode( $result['all'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( ! is_string( $json ) ) {
					throw new \RuntimeException( 'JSON encode failed for job store.' );
				}
				ftruncate( $fh, 0 );
				rewind( $fh );
				fwrite( $fh, $json . "\n" );
				fflush( $fh );
			}
			flock( $fh, LOCK_UN );
			return $result;
		} finally {
			fclose( $fh );
		}
	}

	/** @param array<string,mixed> $all */
	private function writeAll( array $all ): void {
		$json = json_encode( $all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'JSON encode failed for job store.' );
		}
		$tmp = $this->path . '.tmp.' . getmypid();
		if ( false === file_put_contents( $tmp, $json . "\n", LOCK_EX ) ) {
			throw new \RuntimeException( 'Cannot write job store temp.' );
		}
		if ( ! rename( $tmp, $this->path ) ) {
			@unlink( $tmp );
			throw new \RuntimeException( 'Cannot replace job store.' );
		}
		@chmod( $this->path, 0600 );
	}
}
