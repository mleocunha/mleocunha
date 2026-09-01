<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http\CatalogI18n;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http\CookieSessionPort;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http\HttpKernel;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http\Request;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Identity\FileJsonUserStore;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\NodeRuntime;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;
use RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll\RsvFormat;
use RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll\RsvImporter;

final class StandaloneHttpTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/ve-http-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/voting', 0700, true );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
	}

	public function test_rsv_importer_and_durable_identity(): void {
		$users = FileJsonUserStore::open( $this->root . '/voting/identity.json' );
		$text  = RsvFormat::headerLine() . "\n"
			. "eleitor01:1:1:z:s:Ana:+5511:ana@ex.com:Rua A, 1:eleitor:Senha1\n"
			. "auditor1:2:2:z:s:Bob::bob@ex.com::auditor:Senha2\n";
		$res = RsvImporter::importText( $text, $users, true );
		$this->assertSame( 2, $res['created'] );
		$this->assertSame( 0, count( $res['errors'] ) );
		$this->assertSame( 1, $users->countByRole( 'subscriber' ) );
		$this->assertNotNull( $users->verifyPassword( 'eleitor01', 'Senha1' ) );

		$again = FileJsonUserStore::open( $this->root . '/voting/identity.json' );
		$this->assertSame( 1, $again->countByRole( 'subscriber' ) );
		$this->assertNotNull( $again->verifyPassword( 'eleitor01', 'Senha1' ) );
	}

	public function test_catalog_i18n_accept_language(): void {
		$this->assertSame( 'pt_BR', CatalogI18n::fromAcceptLanguage( 'pt-BR,pt;q=0.9' ) );
		$this->assertSame( 'fr_FR', CatalogI18n::fromAcceptLanguage( 'fr-FR,fr;q=0.8' ) );
		$i18n = new CatalogI18n( dirname( __DIR__, 3 ) . '/languages/catalogs', 'pt_BR' );
		$this->assertSame( 'pt_BR', $i18n->locale() );
		$this->assertNotSame( 'Elections', $i18n->t( 'Elections' ) );
	}

	public function test_http_login_and_painel(): void {
		$plugin = dirname( __DIR__, 3 );
		$node   = NodeRuntime::create( SiteModes::VOTING, $this->root . '/voting', 'teste', true, 'http://10.42.0.1:8888' );
		$kernel = new HttpKernel( $node, $plugin, 'pt-BR' );

		$loginPage = $kernel->handle(
			new Request( 'GET', '/login', array(), array(), array(), array() )
		);
		$this->assertSame( 200, $loginPage->status );
		$this->assertStringContainsString( 'Entrar', $loginPage->body );

		$post = $kernel->handle(
			new Request(
				'POST',
				'/login',
				array(),
				array( 'login' => 'admin', 'password' => 'AdminPoC1!', 'next' => '/painel' ),
				array(),
				array()
			)
		);
		$this->assertSame( 302, $post->status );
		$this->assertArrayHasKey( 'Set-Cookie', $post->headers );
		preg_match( '/' . CookieSessionPort::COOKIE . '=([^;]+)/', $post->headers['Set-Cookie'], $m );
		$this->assertNotEmpty( $m[1] ?? '' );

		$painel = $kernel->handle(
			new Request(
				'GET',
				'/painel',
				array(),
				array(),
				array( CookieSessionPort::COOKIE => $m[1] ),
				array()
			)
		);
		$this->assertSame( 200, $painel->status );
		$this->assertStringContainsString( 'Painel de Controle Eleitoral', $painel->body );
		$this->assertStringContainsString( 'Cadastro', $painel->body );
	}

	public function test_http_key_authority_mode_home(): void {
		mkdir( $this->root . '/ka', 0700, true );
		$plugin = dirname( __DIR__, 3 );
		$node   = NodeRuntime::create( SiteModes::KEY_AUTHORITY, $this->root . '/ka', 'teste', true );
		$kernel = new HttpKernel( $node, $plugin, 'en-US' );
		$post   = $kernel->handle(
			new Request( 'POST', '/login', array(), array( 'login' => 'admin', 'password' => 'AdminPoC1!', 'next' => '/painel' ), array(), array() )
		);
		preg_match( '/' . CookieSessionPort::COOKIE . '=([^;]+)/', $post->headers['Set-Cookie'] ?? '', $m );
		$cookie = $m[1] ?? '';
		$home = $kernel->handle(
			new Request( 'GET', '/painel', array(), array(), array( CookieSessionPort::COOKIE => $cookie ), array() )
		);
		$this->assertStringContainsString( 'Key Authority', $home->body );
		$this->assertStringContainsString( 'Autoridades', $home->body );

		$keygenBlocked = $kernel->handle(
			new Request( 'GET', '/painel/keygen', array(), array(), array( CookieSessionPort::COOKIE => $cookie ), array() )
		);
		$this->assertStringContainsString( '/painel/autoridades', $keygenBlocked->body );
		$this->assertStringNotContainsString( 'Gerar chave + atribuir parcelas', $keygenBlocked->body );
	}

	public function test_ka_autoridades_then_assign_shares(): void {
		mkdir( $this->root . '/ka', 0700, true );
		mkdir( $this->root . '/courier', 0700, true );
		$plugin = dirname( __DIR__, 3 );
		$node   = NodeRuntime::create( SiteModes::KEY_AUTHORITY, $this->root . '/ka', 'teste', true );
		$kernel = new HttpKernel( $node, $plugin, 'pt-BR' );
		$login  = $kernel->handle(
			new Request( 'POST', '/login', array(), array( 'login' => 'admin', 'password' => 'AdminPoC1!', 'next' => '/painel' ), array(), array() )
		);
		preg_match( '/' . CookieSessionPort::COOKIE . '=([^;]+)/', $login->headers['Set-Cookie'] ?? '', $m );
		$cookie = array( CookieSessionPort::COOKIE => $m[1] ?? '' );

		$ids = array();
		foreach ( array( 'aut1', 'aut2', 'aut3' ) as $loginName ) {
			$res = $kernel->handle(
				new Request(
					'POST',
					'/painel/autoridades',
					array(),
					array(
						'login'       => $loginName,
						'email'       => $loginName . '@ex.test',
						'displayName' => 'Aut ' . $loginName,
						'password'    => 'SenhaAut1!',
					),
					$cookie,
					array()
				)
			);
			$this->assertSame( 200, $res->status );
			$this->assertStringContainsString( 'cadastrada', $res->body );
		}
		$officials = $node->users->listByRole( 'editor' );
		$this->assertCount( 3, $officials );
		foreach ( $officials as $o ) {
			$ids[] = (string) (int) $o['id'];
		}

		$gen = $kernel->handle(
			new Request(
				'POST',
				'/painel/keygen',
				array(),
				array(
					'bits'         => '512',
					'threshold'    => '2',
					'shares'       => '3',
					'official_ids' => $ids,
				),
				$cookie,
				array()
			)
		);
		$this->assertSame( 200, $gen->status );
		$this->assertStringContainsString( 'parcelas atribuídas', $gen->body );
		$keys = $node->persistence->keys->listActive();
		$this->assertNotEmpty( $keys );
		$shares = $node->persistence->shares->listByKey( (int) $keys[0]['id'] );
		$this->assertCount( 3, $shares );
		$this->assertFileExists( $this->root . '/courier/public-key.json' );
		$this->assertFileExists( $this->root . '/courier/parcela-1.json' );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
		@rmdir( $dir );
	}
}
