<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit\Identity;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Persistence\JsonDocumentStore;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Certificates\X509CertificateInspector;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Federation\FederatedIdentityStore;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\Federation\GovBrOidcClient;

final class GovBrAndX509Test extends TestCase {

	public function test_pkce_bundle_and_disabled_by_default(): void {
		putenv( 'VE_GOVBR_ENABLED' );
		putenv( 'VE_GOVBR_CLIENT_ID' );
		$this->assertFalse( GovBrOidcClient::isAvailable() );
		$pkce = GovBrOidcClient::createPkceBundle();
		$this->assertGreaterThanOrEqual( 43, strlen( $pkce['code_verifier'] ) );
		$this->assertNotSame( '', $pkce['state'] );
		$this->assertNotSame( '', $pkce['code_challenge'] );
	}

	public function test_id_token_claims_validation(): void {
		$config = array(
			'enabled'                => true,
			'environment'            => 'staging',
			'client_id'              => 'client-x',
			'client_secret'          => '',
			'redirect_uri'           => 'https://app.example/auth/govbr/callback',
			'issuer'                 => GovBrOidcClient::STAGING_ISSUER,
			'authorization_endpoint' => GovBrOidcClient::STAGING_AUTHORIZE,
			'token_endpoint'         => GovBrOidcClient::STAGING_TOKEN,
			'jwks_uri'               => '',
			'scopes'                 => 'openid',
		);
		$claims = array(
			'iss'   => GovBrOidcClient::STAGING_ISSUER,
			'aud'   => 'client-x',
			'exp'   => time() + 60,
			'nonce' => 'n1',
			'sub'   => 'sub-123',
		);
		GovBrOidcClient::validateIdTokenClaims( $claims, $config, 'n1' );
		$this->expectException( \RuntimeException::class );
		GovBrOidcClient::validateIdTokenClaims( $claims, $config, 'wrong' );
	}

	public function test_federated_link_prevents_takeover(): void {
		$path  = sys_get_temp_dir() . '/ve-fed-' . bin2hex( random_bytes( 4 ) ) . '.json';
		$store = new JsonDocumentStore( $path );
		$fed   = new FederatedIdentityStore( $store );
		$fed->link( FederatedIdentityStore::PROVIDER_GOVBR, 'iss', 'sub-a', 10 );
		$this->assertSame( 10, $fed->findUserId( FederatedIdentityStore::PROVIDER_GOVBR, 'iss', 'sub-a' ) );
		$this->expectException( \RuntimeException::class );
		$fed->link( FederatedIdentityStore::PROVIDER_GOVBR, 'iss', 'sub-a', 99 );
		@unlink( $path );
	}

	public function test_x509_rejects_garbage_and_marks_untrusted_without_anchors(): void {
		$bad = X509CertificateInspector::inspectPem( 'not-a-cert' );
		$this->assertSame( X509CertificateInspector::RESULT_INVALID, $bad['status'] );

		// Self-signed throwaway for structural parse (not ICP trust).
		$dn   = array( 'countryName' => 'BR', 'organizationName' => 'Test', 'commonName' => 'teste.local' );
		$priv = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		$this->assertNotFalse( $priv );
		$csr = openssl_csr_new( $dn, $priv, array( 'digest_alg' => 'sha256' ) );
		$this->assertNotFalse( $csr );
		$x509 = openssl_csr_sign( $csr, null, $priv, 1, array( 'digest_alg' => 'sha256' ) );
		$this->assertNotFalse( $x509 );
		openssl_x509_export( $x509, $pem );
		$res = X509CertificateInspector::inspectPem( $pem );
		$this->assertSame( X509CertificateInspector::RESULT_UNTRUSTED, $res['status'] );
		$this->assertNotSame( '', $res['fingerprint_sha256'] );
		$this->assertContains( 'chain_not_verified_missing_official_anchors', $res['reasons'] );
	}
}
