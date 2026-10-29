<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Certificates;

/**
 * Inspeção estrutural de certificados X.509 (incl. candidatos ICP-Brasil).
 *
 * NÃO declara confiança ICP-Brasil sem âncoras oficiais ITI carregadas.
 * NÃO aceita upload de .cer como prova de posse da chave privada.
 */
final class X509CertificateInspector {

	public const RESULT_STRUCTURALLY_OK = 'structurally_ok';
	public const RESULT_INVALID         = 'invalid';
	public const RESULT_EXPIRED         = 'expired';
	public const RESULT_NOT_YET_VALID   = 'not_yet_valid';
	public const RESULT_UNTRUSTED       = 'untrusted_without_anchors';

	/**
	 * @return array{
	 *   status:string,
	 *   fingerprint_sha256:string,
	 *   subject:string,
	 *   issuer:string,
	 *   serial:string,
	 *   valid_from:string,
	 *   valid_to:string,
	 *   reasons:list<string>
	 * }
	 */
	public static function inspectPem( string $pem, ?int $referenceTime = null ): array {
		$ref = $referenceTime ?? time();
		$reasons = array();
		$cert = @openssl_x509_read( $pem );
		if ( false === $cert ) {
			return self::result( self::RESULT_INVALID, '', '', '', '', '', '', array( 'pem_or_der_parse_failed' ) );
		}
		$parsed = openssl_x509_parse( $cert, false );
		if ( ! is_array( $parsed ) ) {
			return self::result( self::RESULT_INVALID, '', '', '', '', '', '', array( 'x509_parse_failed' ) );
		}
		$fp = openssl_x509_fingerprint( $cert, 'sha256', false );
		$fp = is_string( $fp ) ? strtolower( $fp ) : '';
		$subject = self::dnToString( $parsed['subject'] ?? array() );
		$issuer  = self::dnToString( $parsed['issuer'] ?? array() );
		$serial  = (string) ( $parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? '' );
		$from    = isset( $parsed['validFrom_time_t'] ) ? gmdate( 'c', (int) $parsed['validFrom_time_t'] ) : '';
		$to      = isset( $parsed['validTo_time_t'] ) ? gmdate( 'c', (int) $parsed['validTo_time_t'] ) : '';

		$status = self::RESULT_STRUCTURALLY_OK;
		if ( isset( $parsed['validFrom_time_t'] ) && (int) $parsed['validFrom_time_t'] > $ref ) {
			$status   = self::RESULT_NOT_YET_VALID;
			$reasons[] = 'not_yet_valid';
		}
		if ( isset( $parsed['validTo_time_t'] ) && (int) $parsed['validTo_time_t'] < $ref ) {
			$status    = self::RESULT_EXPIRED;
			$reasons[] = 'expired';
		}
		// Sem âncoras ICP oficiais carregadas, nunca promover a "válido para ICP-Brasil".
		$reasons[] = 'chain_not_verified_missing_official_anchors';
		if ( self::RESULT_STRUCTURALLY_OK === $status ) {
			$status = self::RESULT_UNTRUSTED;
		}

		return self::result( $status, $fp, $subject, $issuer, $serial, $from, $to, $reasons );
	}

	/**
	 * @param array<string,mixed>|string $dn
	 */
	private static function dnToString( array|string $dn ): string {
		if ( is_string( $dn ) ) {
			return $dn;
		}
		$parts = array();
		foreach ( $dn as $k => $v ) {
			if ( is_array( $v ) ) {
				$v = implode( '+', array_map( 'strval', $v ) );
			}
			$parts[] = $k . '=' . $v;
		}
		return implode( ', ', $parts );
	}

	/**
	 * @param list<string> $reasons
	 * @return array{
	 *   status:string,
	 *   fingerprint_sha256:string,
	 *   subject:string,
	 *   issuer:string,
	 *   serial:string,
	 *   valid_from:string,
	 *   valid_to:string,
	 *   reasons:list<string>
	 * }
	 */
	private static function result(
		string $status,
		string $fp,
		string $subject,
		string $issuer,
		string $serial,
		string $from,
		string $to,
		array $reasons
	): array {
		return array(
			'status'              => $status,
			'fingerprint_sha256'  => $fp,
			'subject'             => $subject,
			'issuer'              => $issuer,
			'serial'              => $serial,
			'valid_from'          => $from,
			'valid_to'            => $to,
			'reasons'             => $reasons,
		);
	}
}
