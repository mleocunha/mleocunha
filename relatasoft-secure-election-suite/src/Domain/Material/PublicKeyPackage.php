<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Material;

/**
 * Manual handoff: public ElGamal parameters from key-authority → voting / tallying.
 *
 * Never includes the private exponent. Transport is file/courier only (no sync).
 */
final class PublicKeyPackage {

	public const FORMAT = 've-public-key-v1';

	/**
	 * @param array{
	 *   key_label?:string,
	 *   key_size?:int,
	 *   p:string,q:string,g:string,y:string,
	 *   field_prime?:string,
	 *   threshold_t?:int,
	 *   total_n?:int,
	 *   source_mode?:string,
	 *   cliente_id?:string,
	 *   cliente_nome?:string
	 * } $payload
	 * @return array<string,mixed>
	 */
	public static function build( array $payload ): array {
		$body = array(
			'format'       => self::FORMAT,
			'exported_at'  => gmdate( 'c' ),
			'source_mode'  => (string) ( $payload['source_mode'] ?? 'key_authority' ),
			'cliente_id'   => (string) ( $payload['cliente_id'] ?? '' ),
			'cliente_nome' => (string) ( $payload['cliente_nome'] ?? '' ),
			'key_label'    => (string) ( $payload['key_label'] ?? 'piloto' ),
			'key_size'     => (int) ( $payload['key_size'] ?? 512 ),
			'public_key'   => array(
				'p' => (string) $payload['p'],
				'q' => (string) $payload['q'],
				'g' => (string) $payload['g'],
				'y' => (string) $payload['y'],
			),
			'threshold_t'  => (int) ( $payload['threshold_t'] ?? 0 ),
			'total_n'      => (int) ( $payload['total_n'] ?? 0 ),
			'field_prime'  => (string) ( $payload['field_prime'] ?? '' ),
		);
		$body['checksum'] = self::checksum( $body );
		return $body;
	}

	/**
	 * @param array<string,mixed> $package
	 * @return array{ok:bool,error?:string}
	 */
	public static function validate( array $package ): array {
		if ( ( $package['format'] ?? '' ) !== self::FORMAT ) {
			return array( 'ok' => false, 'error' => 'format' );
		}
		// Courier packages must never carry the private exponent.
		if ( array_key_exists( 'private_x', $package )
			|| ( isset( $package['public_key'] ) && is_array( $package['public_key'] ) && array_key_exists( 'private_x', $package['public_key'] ) )
		) {
			return array( 'ok' => false, 'error' => 'private_x' );
		}
		$pk = $package['public_key'] ?? null;
		if ( ! is_array( $pk ) ) {
			return array( 'ok' => false, 'error' => 'public_key' );
		}
		foreach ( array( 'p', 'q', 'g', 'y' ) as $k ) {
			if ( '' === trim( (string) ( $pk[ $k ] ?? '' ) ) ) {
				return array( 'ok' => false, 'error' => 'field:' . $k );
			}
		}
		$expected = (string) ( $package['checksum'] ?? '' );
		$copy     = $package;
		unset( $copy['checksum'] );
		if ( '' === $expected || ! hash_equals( $expected, self::checksum( $copy ) ) ) {
			return array( 'ok' => false, 'error' => 'checksum' );
		}
		return array( 'ok' => true );
	}

	/** @param array<string,mixed> $body */
	public static function checksum( array $body ): string {
		unset( $body['checksum'] );
		$json = json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	/** @param array<string,mixed> $package */
	public static function toJson( array $package ): string {
		$json = json_encode( $package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json . "\n" : '';
	}

	/** @return array<string,mixed>|null */
	public static function fromJson( string $json ): ?array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$v = self::validate( $data );
		return ! empty( $v['ok'] ) ? $data : null;
	}
}
