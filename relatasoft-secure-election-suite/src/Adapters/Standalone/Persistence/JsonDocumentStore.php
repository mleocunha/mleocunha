<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Persistence;

/**
 * Single-file JSON bag for one Adapter #2 node (no sync across nodes).
 *
 * Atomic replace via temp file + rename. Tables are either:
 * - auto-id row maps: { "autoId": N, "rows": { "1": {...}, ... } }
 * - custom blobs (elections, signed_results)
 */
final class JsonDocumentStore {

	/** @var array<string,mixed> */
	private array $data = array();

	public function __construct(
		private readonly string $path,
	) {
		$dir = dirname( $this->path );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Cannot create store directory: ' . $dir );
		}
		$this->load();
	}

	public function path(): string {
		return $this->path;
	}

	/**
	 * @return array{autoId:int,rows:array<int,array<string,mixed>>}
	 */
	public function autoTable( string $name ): array {
		$t = $this->data[ $name ] ?? null;
		if ( ! is_array( $t ) ) {
			$t = array( 'autoId' => 1, 'rows' => array() );
			$this->data[ $name ] = $t;
		}
		$autoId = (int) ( $t['autoId'] ?? 1 );
		$rows   = array();
		foreach ( (array) ( $t['rows'] ?? array() ) as $id => $row ) {
			if ( is_array( $row ) ) {
				$rows[ (int) $id ] = $row;
			}
		}
		return array( 'autoId' => $autoId, 'rows' => $rows );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public function writeAutoTable( string $name, int $autoId, array $rows ): void {
		$encoded = array();
		foreach ( $rows as $id => $row ) {
			$encoded[ (string) (int) $id ] = $row;
		}
		$this->data[ $name ] = array(
			'autoId' => $autoId,
			'rows'   => $encoded,
		);
		$this->flush();
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function insert( string $name, array $data ): int {
		$t                 = $this->autoTable( $name );
		$id                = $t['autoId']++;
		$data['id']        = $id;
		$t['rows'][ $id ]  = $data;
		$this->writeAutoTable( $name, $t['autoId'], $t['rows'] );
		return $id;
	}

	/** @return array<string,mixed>|null */
	public function find( string $name, int $id ): ?array {
		$t = $this->autoTable( $name );
		return $t['rows'][ $id ] ?? null;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function all( string $name ): array {
		return array_values( $this->autoTable( $name )['rows'] );
	}

	/**
	 * @param callable(array{autoId:int,rows:array<int,array<string,mixed>>}):array{autoId:int,rows:array<int,array<string,mixed>>} $mutator
	 */
	public function mutateAuto( string $name, callable $mutator ): void {
		$t = $this->autoTable( $name );
		$t = $mutator( $t );
		$this->writeAutoTable( $name, (int) $t['autoId'], $t['rows'] );
	}

	/** @return array<string,mixed> */
	public function blob( string $name ): array {
		$b = $this->data[ $name ] ?? array();
		return is_array( $b ) ? $b : array();
	}

	/** @param array<string,mixed> $blob */
	public function writeBlob( string $name, array $blob ): void {
		$this->data[ $name ] = $blob;
		$this->flush();
	}

	private function load(): void {
		if ( ! is_readable( $this->path ) ) {
			$this->data = array();
			$this->flush();
			return;
		}
		$raw  = file_get_contents( $this->path );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$this->data = is_array( $data ) ? $data : array();
	}

	private function flush(): void {
		$json = json_encode( $this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'JSON encode failed for ' . $this->path );
		}
		$tmp = $this->path . '.tmp.' . getmypid();
		if ( false === file_put_contents( $tmp, $json . "\n", LOCK_EX ) ) {
			throw new \RuntimeException( 'Cannot write ' . $tmp );
		}
		if ( ! rename( $tmp, $this->path ) ) {
			@unlink( $tmp );
			throw new \RuntimeException( 'Cannot replace ' . $this->path );
		}
		@chmod( $this->path, 0600 );
	}
}
