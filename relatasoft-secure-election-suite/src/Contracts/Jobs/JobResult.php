<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs;

/**
 * Host-free failure/success helpers for job ports (A4 / M4).
 *
 * Failure shape: { ok:false, error:string, code:string }
 * Success is a normal public status array (may omit ok or set ok:true).
 */
final class JobResult {

	/**
	 * @return array{ok:false,error:string,code:string}
	 */
	public static function fail( string $code, string $message ): array {
		return array(
			'ok'    => false,
			'error' => $message,
			'code'  => $code,
		);
	}

	/** @param array<string,mixed> $payload */
	public static function isFailure( array $payload ): bool {
		return array_key_exists( 'ok', $payload ) && false === $payload['ok'];
	}

	/** @param array<string,mixed> $payload */
	public static function message( array $payload ): string {
		if ( isset( $payload['error'] ) && is_string( $payload['error'] ) ) {
			return $payload['error'];
		}
		if ( isset( $payload['message'] ) && is_string( $payload['message'] ) ) {
			return $payload['message'];
		}
		return '';
	}

	/** @param array<string,mixed> $payload */
	public static function code( array $payload ): string {
		return isset( $payload['code'] ) && is_string( $payload['code'] ) ? $payload['code'] : 'job_error';
	}
}
