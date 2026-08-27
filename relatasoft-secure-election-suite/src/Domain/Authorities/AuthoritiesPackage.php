<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Authorities;

/**
 * Portable electoral-authorities package (export format ≡ import format).
 */
final class AuthoritiesPackage {

	public const FORMAT = 've-electoral-authorities-v1';

	/**
	 * @param array{
	 *   exported_at?:string,
	 *   source_site?:string,
	 *   source_mode?:string,
	 *   plugin_version?:string,
	 *   authorities:list<array<string,mixed>>
	 * } $payload
	 * @return array<string,mixed>
	 */
	public static function build( array $payload ): array {
		$body = array(
			'format'         => self::FORMAT,
			'exported_at'    => (string) ( $payload['exported_at'] ?? gmdate( 'c' ) ),
			'source_site'    => (string) ( $payload['source_site'] ?? '' ),
			'source_mode'    => (string) ( $payload['source_mode'] ?? '' ),
			'plugin_version' => (string) ( $payload['plugin_version'] ?? '' ),
			'authorities'    => array_values( $payload['authorities'] ?? array() ),
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
		if ( empty( $package['authorities'] ) || ! is_array( $package['authorities'] ) ) {
			return array( 'ok' => false, 'error' => 'empty' );
		}
		$expected = (string) ( $package['checksum'] ?? '' );
		$copy     = $package;
		unset( $copy['checksum'] );
		$actual = self::checksum( $copy );
		if ( '' === $expected || ! hash_equals( $expected, $actual ) ) {
			return array( 'ok' => false, 'error' => 'checksum' );
		}
		foreach ( $package['authorities'] as $i => $row ) {
			if ( ! is_array( $row ) ) {
				return array( 'ok' => false, 'error' => 'row:' . $i );
			}
			$login = trim( (string) ( $row['user_login'] ?? '' ) );
			$email = trim( (string) ( $row['user_email'] ?? '' ) );
			if ( '' === $login || '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				return array( 'ok' => false, 'error' => 'fields:' . $i );
			}
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

	/**
	 * @return array<string,mixed>|null
	 */
	public static function fromJson( string $json ): ?array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$v = self::validate( $data );
		return ! empty( $v['ok'] ) ? $data : null;
	}
}
