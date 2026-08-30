<?php
/**
 * Portable package for electoral authorities (export ↔ import same format).
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Painel\Domain\Authorities\AuthoritiesPackage;

defined( 'ABSPATH' ) || exit;

/**
 * WP-facing wrapper around AuthoritiesPackage with localized errors.
 */
final class ElectoralAuthoritiesPackage {

	public const FORMAT = AuthoritiesPackage::FORMAT;

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
		return AuthoritiesPackage::build( $payload );
	}

	/**
	 * @param array<string,mixed> $package
	 * @return true|\WP_Error
	 */
	public static function validate( array $package ) {
		$v = AuthoritiesPackage::validate( $package );
		if ( ! empty( $v['ok'] ) ) {
			return true;
		}
		$error = (string) ( $v['error'] ?? 'invalid' );
		$messages = array(
			'format'   => __( 'Formato de pacote de autoridades eleitorais não reconhecido.', 'relatasoft-secure-election-suite' ),
			'empty'    => __( 'O pacote não contém autoridades eleitorais.', 'relatasoft-secure-election-suite' ),
			'checksum' => __( 'Checksum do pacote inválido — o arquivo pode estar corrompido.', 'relatasoft-secure-election-suite' ),
		);
		if ( isset( $messages[ $error ] ) ) {
			return new \WP_Error( 'rses_ea_' . $error, $messages[ $error ] );
		}
		return new \WP_Error(
			'rses_ea_invalid',
			__( 'Pacote de autoridades eleitorais inválido.', 'relatasoft-secure-election-suite' )
		);
	}

	/**
	 * @param array<string,mixed> $body
	 */
	public static function checksum( array $body ): string {
		return AuthoritiesPackage::checksum( $body );
	}

	/**
	 * @param array<string,mixed> $package
	 */
	public static function to_json( array $package ): string {
		return AuthoritiesPackage::toJson( $package );
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function from_json( string $json ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'rses_ea_json', __( 'JSON inválido.', 'relatasoft-secure-election-suite' ) );
		}
		$ok = self::validate( $data );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return $data;
	}
}
