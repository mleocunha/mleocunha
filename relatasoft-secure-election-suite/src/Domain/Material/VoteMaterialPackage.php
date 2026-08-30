<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Material;

/**
 * Manual handoff: sealed ballots from voting → tallying (no private key).
 */
final class VoteMaterialPackage {

	public const FORMAT = 've-vote-material-v1';

	/**
	 * @param array{
	 *   election_id?:int,
	 *   round_id?:int,
	 *   public_key_checksum?:string,
	 *   ballots:list<array<string,mixed>>,
	 *   source_mode?:string,
	 *   cliente_id?:string
	 * } $payload
	 * @return array<string,mixed>
	 */
	public static function build( array $payload ): array {
		$body = array(
			'format'              => self::FORMAT,
			'exported_at'         => gmdate( 'c' ),
			'source_mode'         => (string) ( $payload['source_mode'] ?? 'voting' ),
			'cliente_id'          => (string) ( $payload['cliente_id'] ?? '' ),
			'election_id'         => (int) ( $payload['election_id'] ?? 0 ),
			'round_id'            => (int) ( $payload['round_id'] ?? 0 ),
			'public_key_checksum' => (string) ( $payload['public_key_checksum'] ?? '' ),
			'ballots'             => array_values( $payload['ballots'] ?? array() ),
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
		if ( array_key_exists( 'private_x', $package ) ) {
			return array( 'ok' => false, 'error' => 'private_x' );
		}
		if ( empty( $package['ballots'] ) || ! is_array( $package['ballots'] ) ) {
			return array( 'ok' => false, 'error' => 'ballots' );
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
