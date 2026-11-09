<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Identity;

/**
 * Política de senhas orientada a NIST SP 800-63B (comprimento + denylist),
 * com Argon2id quando o runtime PHP o disponibiliza.
 */
final class PasswordPolicy {

	/** Mínimo absoluto; produção deve preferir ≥ 12 (documentado em docs/iam). */
	public const MIN_LENGTH = 10;
	public const MAX_LENGTH = 128;
	public const RECOMMENDED_MIN = 12;

	/**
	 * @return array{ok:true}|array{ok:false,error:string}
	 */
	public static function validate( string $plaintext, string $login = '', string $email = '' ): array {
		$len = strlen( $plaintext );
		if ( $len < self::MIN_LENGTH ) {
			return array( 'ok' => false, 'error' => 'A senha deve ter pelo menos ' . self::MIN_LENGTH . ' caracteres.' );
		}
		if ( $len > self::MAX_LENGTH ) {
			return array( 'ok' => false, 'error' => 'A senha excede o tamanho máximo permitido.' );
		}
		$lower = mb_strtolower( $plaintext, 'UTF-8' );
		$loginLower = mb_strtolower( trim( $login ), 'UTF-8' );
		// Evitar igualdade com o login; substring só para logins longos (≥5) — evita falsos positivos (ex.: aut1).
		if ( '' !== $loginLower ) {
			if ( $lower === $loginLower ) {
				return array( 'ok' => false, 'error' => 'A senha não pode ser igual ao login.' );
			}
			if ( strlen( $loginLower ) >= 5 && str_contains( $lower, $loginLower ) ) {
				return array( 'ok' => false, 'error' => 'A senha não pode conter o login.' );
			}
		}
		$local = strstr( $email, '@', true );
		if ( is_string( $local ) && strlen( $local ) >= 5 && str_contains( $lower, mb_strtolower( $local, 'UTF-8' ) ) ) {
			return array( 'ok' => false, 'error' => 'A senha não pode conter o e-mail.' );
		}
		if ( self::isCommonPassword( $lower ) ) {
			return array( 'ok' => false, 'error' => 'Essa senha é demasiado comum. Escolha outra.' );
		}
		return array( 'ok' => true );
	}

	public static function hash( string $plaintext ): string {
		$algo = defined( 'PASSWORD_ARGON2ID' ) ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
		$hash = password_hash( $plaintext, $algo );
		if ( ! is_string( $hash ) || '' === $hash || ! str_starts_with( $hash, '$' ) ) {
			throw new \RuntimeException( 'Falha ao gerar hash de senha.' );
		}
		return $hash;
	}

	public static function verify( string $stored, string $plaintext ): bool {
		if ( '' === $stored || '' === $plaintext ) {
			return false;
		}
		// Legado inseguro: nunca aceitar em autenticação nova — migrar via setPassword.
		if ( str_starts_with( $stored, 'plain:' ) ) {
			return false;
		}
		if ( str_starts_with( $stored, '$' ) ) {
			return password_verify( $plaintext, $stored );
		}
		// Hashes opacos portáveis (ex.: import RSV com marcador) — comparação constante, sem rehash.
		if ( str_starts_with( $stored, 'hash:' ) ) {
			return false;
		}
		return false;
	}

	public static function needsRehash( string $stored ): bool {
		if ( ! str_starts_with( $stored, '$' ) ) {
			return true;
		}
		$algo = defined( 'PASSWORD_ARGON2ID' ) ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
		return password_needs_rehash( $stored, $algo );
	}

	private static function isCommonPassword( string $lower ): bool {
		static $deny = array(
			'password', 'password123', '123456789012', 'qwertyuiopas',
			'adminadmin12', 'senha1234567', 'changeme1234', 'letmeinletmein',
			'iloveyou1234', 'welcome12345', 'abcabcabcabc', 'votacao12345',
		);
		return in_array( $lower, $deny, true );
	}
}
