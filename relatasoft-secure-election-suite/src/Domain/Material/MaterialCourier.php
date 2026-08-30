<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Material;

/**
 * Explicit file courier for manual material transport between isolated nodes.
 *
 * There is intentionally no sync API — operators copy files between sítios.
 */
final class MaterialCourier {

	public function __construct(
		private readonly string $directory,
	) {
		if ( ! is_dir( $this->directory ) && ! mkdir( $this->directory, 0700, true ) && ! is_dir( $this->directory ) ) {
			throw new \RuntimeException( 'Cannot create courier directory: ' . $this->directory );
		}
	}

	public function path( string $filename ): string {
		$safe = basename( str_replace( array( '\\', "\0" ), '', $filename ) );
		if ( '' === $safe || '.' === $safe || '..' === $safe ) {
			throw new \InvalidArgumentException( 'Invalid courier filename.' );
		}
		return rtrim( $this->directory, '/\\' ) . DIRECTORY_SEPARATOR . $safe;
	}

	/** @param array<string,mixed> $package */
	public function writeJson( string $filename, array $package ): string {
		$path = $this->path( $filename );
		$json = json_encode( $package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'JSON encode failed for ' . $filename );
		}
		if ( false === file_put_contents( $path, $json . "\n", LOCK_EX ) ) {
			throw new \RuntimeException( 'Cannot write ' . $path );
		}
		return $path;
	}

	/** @return array<string,mixed> */
	public function readJson( string $filename ): array {
		$path = $this->path( $filename );
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( 'Missing courier file: ' . $filename );
		}
		$raw  = file_get_contents( $path );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Invalid JSON in ' . $filename );
		}
		return $data;
	}

	public function directory(): string {
		return $this->directory;
	}
}
