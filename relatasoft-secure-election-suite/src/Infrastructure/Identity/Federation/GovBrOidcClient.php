<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Federation;

/**
 * Cliente OIDC Authorization Code + PKCE para Login Único gov.br.
 *
 * Endpoints e escopos vêm da configuração (documentação oficial
 * https://acesso.gov.br/roteiro-tecnico — atualização 30/10/2025).
 * Não habilita fluxo em produção sem VE_GOVBR_ENABLED + client_id.
 *
 * @phpstan-type GovBrConfig array{
 *   enabled:bool,
 *   environment:string,
 *   client_id:string,
 *   client_secret:string,
 *   redirect_uri:string,
 *   issuer:string,
 *   authorization_endpoint:string,
 *   token_endpoint:string,
 *   jwks_uri:string,
 *   scopes:string
 * }
 */
final class GovBrOidcClient {

	/** Staging oficial documentado no roteiro técnico (out/2025). */
	public const STAGING_AUTHORIZE = 'https://sso.staging.acesso.gov.br/authorize';
	public const STAGING_TOKEN     = 'https://sso.staging.acesso.gov.br/token';
	public const STAGING_ISSUER    = 'https://sso.staging.acesso.gov.br';

	/**
	 * @return GovBrConfig
	 */
	public static function fromEnvironment(): array {
		$env = getenv( 'VE_GOVBR_ENVIRONMENT' ) ?: 'staging';
		$enabled = ( getenv( 'VE_GOVBR_ENABLED' ) ?: '' ) === '1';
		$clientId = (string) ( getenv( 'VE_GOVBR_CLIENT_ID' ) ?: '' );
		$defaultAuth = 'staging' === $env ? self::STAGING_AUTHORIZE : '';
		$defaultToken = 'staging' === $env ? self::STAGING_TOKEN : '';
		$defaultIssuer = 'staging' === $env ? self::STAGING_ISSUER : '';
		return array(
			'enabled'                 => $enabled && '' !== $clientId,
			'environment'             => $env,
			'client_id'               => $clientId,
			'client_secret'           => (string) ( getenv( 'VE_GOVBR_CLIENT_SECRET' ) ?: '' ),
			'redirect_uri'            => (string) ( getenv( 'VE_GOVBR_REDIRECT_URI' ) ?: '' ),
			'issuer'                  => (string) ( getenv( 'VE_GOVBR_ISSUER' ) ?: $defaultIssuer ),
			'authorization_endpoint'  => (string) ( getenv( 'VE_GOVBR_AUTHORIZATION_ENDPOINT' ) ?: $defaultAuth ),
			'token_endpoint'          => (string) ( getenv( 'VE_GOVBR_TOKEN_ENDPOINT' ) ?: $defaultToken ),
			'jwks_uri'                => (string) ( getenv( 'VE_GOVBR_JWKS_URI' ) ?: '' ),
			'scopes'                  => (string) ( getenv( 'VE_GOVBR_SCOPES' ) ?: 'openid email profile govbr_confiabilidades govbr_confiabilidades_idtoken' ),
		);
	}

	public static function isAvailable( ?array $config = null ): bool {
		$c = $config ?? self::fromEnvironment();
		return ! empty( $c['enabled'] )
			&& '' !== $c['client_id']
			&& '' !== $c['redirect_uri']
			&& '' !== $c['authorization_endpoint']
			&& '' !== $c['token_endpoint']
			&& '' !== $c['issuer'];
	}

	/** @return array{state:string,nonce:string,code_verifier:string,code_challenge:string} */
	public static function createPkceBundle(): array {
		$state         = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$nonce         = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$codeVerifier  = self::generateCodeVerifier();
		$codeChallenge = rtrim( strtr( base64_encode( hash( 'sha256', $codeVerifier, true ) ), '+/', '-_' ), '=' );
		return array(
			'state'          => $state,
			'nonce'          => $nonce,
			'code_verifier'  => $codeVerifier,
			'code_challenge' => $codeChallenge,
		);
	}

	public static function generateCodeVerifier(): string {
		// RFC 7636: 43–128 caracteres.
		$raw = rtrim( strtr( base64_encode( random_bytes( 64 ) ), '+/', '-_' ), '=' );
		return substr( $raw, 0, 64 );
	}

	/**
	 * @param GovBrConfig $config
	 * @param array{state:string,nonce:string,code_challenge:string} $pkce
	 */
	public static function buildAuthorizeUrl( array $config, array $pkce ): string {
		if ( ! self::isAvailable( $config ) ) {
			throw new \RuntimeException( 'gov.br não configurado ou não habilitado.' );
		}
		$q = http_build_query(
			array(
				'response_type'         => 'code',
				'client_id'             => $config['client_id'],
				'scope'                 => $config['scopes'],
				'redirect_uri'          => $config['redirect_uri'],
				'nonce'                 => $pkce['nonce'],
				'state'                 => $pkce['state'],
				'code_challenge'        => $pkce['code_challenge'],
				'code_challenge_method' => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		return $config['authorization_endpoint'] . '?' . $q;
	}

	/**
	 * Validação estrutural mínima de claims do ID Token (sem JWKS ainda).
	 * A verificação criptográfica completa exige JWKS_URI configurado e biblioteca/OpenSSL.
	 *
	 * @param array<string,mixed> $claims
	 * @param GovBrConfig $config
	 */
	public static function validateIdTokenClaims( array $claims, array $config, string $expectedNonce ): void {
		if ( ( $claims['iss'] ?? '' ) !== $config['issuer'] ) {
			throw new \RuntimeException( 'iss inválido.' );
		}
		$aud = $claims['aud'] ?? null;
		$audOk = is_string( $aud )
			? $aud === $config['client_id']
			: ( is_array( $aud ) && in_array( $config['client_id'], $aud, true ) );
		if ( ! $audOk ) {
			throw new \RuntimeException( 'aud inválido.' );
		}
		$exp = (int) ( $claims['exp'] ?? 0 );
		if ( $exp < time() ) {
			throw new \RuntimeException( 'id_token expirado.' );
		}
		if ( ( $claims['nonce'] ?? '' ) !== $expectedNonce ) {
			throw new \RuntimeException( 'nonce inválido.' );
		}
		if ( '' === (string) ( $claims['sub'] ?? '' ) ) {
			throw new \RuntimeException( 'sub ausente.' );
		}
	}

	/** Decodifica payload JWT sem verificar assinatura (só para testes de claims). */
	public static function decodeJwtPayloadUnsafe( string $jwt ): array {
		$parts = explode( '.', $jwt );
		if ( count( $parts ) < 2 ) {
			throw new \InvalidArgumentException( 'JWT malformado.' );
		}
		$json = base64_decode( strtr( $parts[1], '-_', '+/' ), true );
		if ( false === $json ) {
			throw new \InvalidArgumentException( 'payload JWT inválido.' );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new \InvalidArgumentException( 'payload JWT não é objeto.' );
		}
		return $data;
	}
}
