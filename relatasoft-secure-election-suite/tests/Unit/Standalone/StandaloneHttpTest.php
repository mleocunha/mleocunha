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
		$this->assertStringContainsString( 'Chaves', $home->body );
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
					'key_title'    => 'Eleição teste rótulo',
					'official_ids' => $ids,
				),
				$cookie,
				array()
			)
		);
		$this->assertSame( 200, $gen->status );
		$this->assertStringContainsString( 'parcelas atribuídas', $gen->body );
		$this->assertStringContainsString( '512 bits', $gen->body );
		$keys = $node->persistence->keys->listActive();
		$this->assertNotEmpty( $keys );
		$this->assertSame( 'Eleição teste rótulo', (string) ( $keys[0]['display_name'] ?? '' ) );
		$this->assertSame( 512, (int) ( $keys[0]['key_size'] ?? 0 ) );
		$shares = $node->persistence->shares->listByKey( (int) $keys[0]['id'] );
		$this->assertCount( 3, $shares );
		$this->assertFileExists( $this->root . '/courier/public-key.json' );
		$pkgJson = (string) file_get_contents( $this->root . '/courier/public-key.json' );
		$pkg     = json_decode( $pkgJson, true );
		$this->assertIsArray( $pkg );
		$this->assertSame( 512, (int) ( $pkg['key_size'] ?? 0 ) );
		$this->assertFileExists( $this->root . '/courier/parcela-1.json' );
		$this->assertFileExists( $this->root . '/courier/authorities.json' );

		$kid = (int) $keys[0]['id'];
		$view = $kernel->handle(
			new Request( 'GET', '/painel/chave/' . $kid, array(), array(), $cookie, array() )
		);
		$this->assertSame( 200, $view->status );
		$this->assertStringContainsString( 'Eleição teste rótulo', $view->body );
		$this->assertStringContainsString( '512 bits', $view->body );
		$this->assertStringContainsString( 'Copiar JSON', $view->body );
		$this->assertStringContainsString( 'id="eliminar"', $view->body );
		$this->assertStringContainsString( 'Confirmo', $view->body );
		$this->assertStringContainsString( 'name="confirm_word"', $view->body );
		$dl = $kernel->handle(
			new Request( 'GET', '/painel/chave/' . $kid . '.json', array(), array(), $cookie, array() )
		);
		$this->assertSame( 200, $dl->status );
		$this->assertStringContainsString( 'application/json', (string) ( $dl->headers['Content-Type'] ?? '' ) );
		$this->assertStringContainsString( 've-public-key-v1', $dl->body );
		$this->assertStringContainsString( '"key_size": 512', $dl->body );

		$page = $kernel->handle(
			new Request( 'GET', '/painel/keygen', array(), array(), $cookie, array() )
		);
		$this->assertStringContainsString( 've-keygen-progress', $page->body );
		$this->assertStringContainsString( 've-keygen-form', $page->body );
		$this->assertStringContainsString( 'Chaves ativas', $page->body );
		$this->assertStringNotContainsString( 'Chaves activas', $page->body );
		$this->assertStringContainsString( 'Tamanho da chave', $page->body );
		$this->assertStringContainsString( 'value="4096"', $page->body );
		$this->assertStringContainsString( '512 bits', $page->body );
	}

	public function test_keygen_rejects_invalid_bit_size(): void {
		if ( ! is_dir( $this->root . '/ka' ) ) {
			mkdir( $this->root . '/ka', 0700, true );
		}
		$plugin = dirname( __DIR__, 3 );
		$node   = NodeRuntime::create( SiteModes::KEY_AUTHORITY, $this->root . '/ka', 'teste', true );
		$kernel = new HttpKernel( $node, $plugin, 'pt-BR' );
		$login  = $kernel->handle(
			new Request( 'POST', '/login', array(), array( 'login' => 'admin', 'password' => 'AdminPoC1!', 'next' => '/painel' ), array(), array() )
		);
		preg_match( '/' . CookieSessionPort::COOKIE . '=([^;]+)/', $login->headers['Set-Cookie'] ?? '', $m );
		$cookie = array( CookieSessionPort::COOKIE => $m[1] ?? '' );
		foreach ( array( 'a1', 'a2' ) as $loginName ) {
			$kernel->handle(
				new Request(
					'POST',
					'/painel/autoridades',
					array(),
					array(
						'action'      => 'create',
						'login'       => $loginName,
						'email'       => $loginName . '@ex.test',
						'displayName' => $loginName,
						'password'    => 'SenhaAut1!',
					),
					$cookie,
					array()
				)
			);
		}
		$ids = array_map( static fn( $o ) => (string) (int) $o['id'], $node->users->listByRole( 'editor' ) );
		$bad = $kernel->handle(
			new Request(
				'POST',
				'/painel/keygen',
				array(),
				array(
					'bits'         => '4097',
					'threshold'    => '2',
					'shares'       => '2',
					'official_ids' => $ids,
				),
				$cookie,
				array()
			)
		);
		$this->assertSame( 200, $bad->status );
		$this->assertStringContainsString( 'Tamanho de chave inválido', $bad->body );
		$this->assertSame( array(), $node->persistence->keys->listActive() );

		// Antigo teto silencioso a 1024: 1500 já não é aceite (nem cortado).
		$clamped = $kernel->handle(
			new Request(
				'POST',
				'/painel/keygen',
				array(),
				array(
					'bits'         => '1500',
					'threshold'    => '2',
					'shares'       => '2',
					'official_ids' => $ids,
				),
				$cookie,
				array()
			)
		);
		$this->assertStringContainsString( 'Tamanho de chave inválido', $clamped->body );
		$this->assertSame( array(), $node->persistence->keys->listActive() );
	}

	public function test_delete_key_requires_case_sensitive_confirmo(): void {
		foreach ( array( 'ka', 'courier' ) as $dir ) {
			$path = $this->root . '/' . $dir;
			if ( ! is_dir( $path ) ) {
				mkdir( $path, 0700, true );
			}
		}
		$plugin = dirname( __DIR__, 3 );
		$node   = NodeRuntime::create( SiteModes::KEY_AUTHORITY, $this->root . '/ka', 'teste', true );
		$kernel = new HttpKernel( $node, $plugin, 'pt-BR' );
		$login  = $kernel->handle(
			new Request( 'POST', '/login', array(), array( 'login' => 'admin', 'password' => 'AdminPoC1!', 'next' => '/painel' ), array(), array() )
		);
		preg_match( '/' . CookieSessionPort::COOKIE . '=([^;]+)/', $login->headers['Set-Cookie'] ?? '', $m );
		$cookie = array( CookieSessionPort::COOKIE => $m[1] ?? '' );
		foreach ( array( 'd1', 'd2' ) as $loginName ) {
			$kernel->handle(
				new Request(
					'POST',
					'/painel/autoridades',
					array(),
					array(
						'action'      => 'create',
						'login'       => $loginName,
						'email'       => $loginName . '@ex.test',
						'displayName' => $loginName,
						'password'    => 'SenhaAut1!',
					),
					$cookie,
					array()
				)
			);
		}
		$ids = array_map( static fn( $o ) => (string) (int) $o['id'], $node->users->listByRole( 'editor' ) );
		$kernel->handle(
			new Request(
				'POST',
				'/painel/keygen',
				array(),
				array(
					'bits'         => '512',
					'threshold'    => '2',
					'shares'       => '2',
					'key_title'    => 'Para eliminar',
					'official_ids' => $ids,
				),
				$cookie,
				array()
			)
		);
		$keys = $node->persistence->keys->listActive();
		$this->assertCount( 1, $keys );
		$kid = (int) $keys[0]['id'];

		$wrongCase = $kernel->handle(
			new Request(
				'POST',
				'/painel/chave/' . $kid,
				array(),
				array(
					'action'       => 'delete_key',
					'key_id'       => (string) $kid,
					'confirm_word' => 'confirmo',
				),
				$cookie,
				array()
			)
		);
		$this->assertSame( 200, $wrongCase->status );
		$this->assertStringContainsString( 'Eliminação cancelada', $wrongCase->body );
		$this->assertCount( 1, $node->persistence->keys->listActive() );

		$ok = $kernel->handle(
			new Request(
				'POST',
				'/painel/chave/' . $kid,
				array(),
				array(
					'action'       => 'delete_key',
					'key_id'       => (string) $kid,
					'confirm_word' => 'Confirmo',
				),
				$cookie,
				array()
			)
		);
		$this->assertSame( 302, $ok->status );
		$this->assertStringContainsString( '/painel/keygen', (string) ( $ok->headers['Location'] ?? '' ) );
		$this->assertSame( array(), $node->persistence->keys->listActive() );
	}

	public function test_catalog_destructive_confirm_words_per_locale(): void {
		$plugin = dirname( __DIR__, 3 );
		$cats   = $plugin . '/languages/catalogs';
		$pt     = new CatalogI18n( $cats, 'pt_BR' );
		$this->assertSame( 'Confirmo', $pt->destructiveConfirmWord() );
		$this->assertTrue( $pt->matchesDestructiveConfirm( 'Confirmo' ) );
		$this->assertFalse( $pt->matchesDestructiveConfirm( 'confirmo' ) );
		$en = new CatalogI18n( $cats, 'en_US' );
		$this->assertSame( 'Confirm', $en->destructiveConfirmWord() );
		$fr = new CatalogI18n( $cats, 'fr_FR' );
		$this->assertSame( 'Confirme', $fr->destructiveConfirmWord() );
	}

	public function test_keygen_ndjson_progress_stream(): void {
		if ( ! is_dir( $this->root . '/ka' ) ) {
			mkdir( $this->root . '/ka', 0700, true );
		}
		if ( ! is_dir( $this->root . '/courier' ) ) {
			mkdir( $this->root . '/courier', 0700, true );
		}
		$plugin = dirname( __DIR__, 3 );
		$node   = NodeRuntime::create( SiteModes::KEY_AUTHORITY, $this->root . '/ka', 'teste', true );
		$kernel = new HttpKernel( $node, $plugin, 'pt-BR' );
		$login  = $kernel->handle(
			new Request( 'POST', '/login', array(), array( 'login' => 'admin', 'password' => 'AdminPoC1!', 'next' => '/painel' ), array(), array() )
		);
		preg_match( '/' . CookieSessionPort::COOKIE . '=([^;]+)/', $login->headers['Set-Cookie'] ?? '', $m );
		$cookie = array( CookieSessionPort::COOKIE => $m[1] ?? '' );
		foreach ( array( 'p1', 'p2' ) as $loginName ) {
			$kernel->handle(
				new Request(
					'POST',
					'/painel/autoridades',
					array(),
					array(
						'action'      => 'create',
						'login'       => $loginName,
						'email'       => $loginName . '@ex.test',
						'displayName' => $loginName,
						'password'    => 'SenhaAut1!',
					),
					$cookie,
					array()
				)
			);
		}
		$ids = array_map( static fn( $o ) => (string) (int) $o['id'], $node->users->listByRole( 'editor' ) );
		$res = $kernel->handle(
			new Request(
				'POST',
				'/painel/keygen',
				array(),
				array(
					'bits'         => '512',
					'threshold'    => '2',
					'shares'       => '2',
					'official_ids' => $ids,
					'progress'     => '1',
				),
				$cookie,
				array( 'HTTP_ACCEPT' => 'application/x-ndjson' )
			)
		);
		$this->assertSame( 'application/x-ndjson; charset=UTF-8', $res->headers['Content-Type'] ?? '' );
		$this->assertIsCallable( $res->streamer );
		ob_start();
		( $res->streamer )();
		$out = (string) ob_get_clean();
		$this->assertStringContainsString( '"percent":12', $out );
		$this->assertStringContainsString( '"done":true', $out );
		$this->assertStringContainsString( '"ok":true', $out );
	}

	public function test_voting_and_tallying_import_authorities_and_submit_share(): void {
		foreach ( array( 'ka', 'tallying', 'courier' ) as $dir ) {
			$path = $this->root . '/' . $dir;
			if ( ! is_dir( $path ) ) {
				mkdir( $path, 0700, true );
			}
		}
		$plugin = dirname( __DIR__, 3 );

		$ka = NodeRuntime::create( SiteModes::KEY_AUTHORITY, $this->root . '/ka', 'teste', true );
		$kk = new HttpKernel( $ka, $plugin, 'pt-BR' );
		$loginKa = $kk->handle(
			new Request( 'POST', '/login', array(), array( 'login' => 'admin', 'password' => 'AdminPoC1!', 'next' => '/painel' ), array(), array() )
		);
		preg_match( '/' . CookieSessionPort::COOKIE . '=([^;]+)/', $loginKa->headers['Set-Cookie'] ?? '', $m );
		$cKa = array( CookieSessionPort::COOKIE => $m[1] ?? '' );
		foreach ( array( 'aut1', 'aut2', 'aut3' ) as $loginName ) {
			$kk->handle(
				new Request(
					'POST',
					'/painel/autoridades',
					array(),
					array(
						'action'      => 'create',
						'login'       => $loginName,
						'email'       => $loginName . '@ex.test',
						'displayName' => $loginName,
						'password'    => 'SenhaAut1!',
					),
					$cKa,
					array()
				)
			);
		}
		$officials = $ka->users->listByRole( 'editor' );
		$ids       = array_map( static fn( $o ) => (string) (int) $o['id'], $officials );
		$kk->handle(
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
				$cKa,
				array()
			)
		);
		$this->assertFileExists( $this->root . '/courier/authorities.json' );

		$voting = NodeRuntime::create( SiteModes::VOTING, $this->root . '/voting', 'teste', true );
		$vk     = new HttpKernel( $voting, $plugin, 'pt-BR' );
		$loginV = $vk->handle(
			new Request( 'POST', '/login', array(), array( 'login' => 'admin', 'password' => 'AdminPoC1!', 'next' => '/painel' ), array(), array() )
		);
		preg_match( '/' . CookieSessionPort::COOKIE . '=([^;]+)/', $loginV->headers['Set-Cookie'] ?? '', $mv );
		$cV = array( CookieSessionPort::COOKIE => $mv[1] ?? '' );
		$impV = $vk->handle(
			new Request( 'POST', '/painel/autoridades', array(), array( 'action' => 'import_courier' ), $cV, array() )
		);
		$this->assertStringContainsString( 'criados 3', $impV->body );
		$this->assertSame( 3, $voting->users->countByRole( 'editor' ) );
		$this->assertNotNull( $voting->users->verifyPassword( 'aut1', 'SenhaAut1!' ) );

		$tally = NodeRuntime::create( SiteModes::TALLYING, $this->root . '/tallying', 'teste', true );
		$tk    = new HttpKernel( $tally, $plugin, 'pt-BR' );
		$loginT = $tk->handle(
			new Request( 'POST', '/login', array(), array( 'login' => 'admin', 'password' => 'AdminPoC1!', 'next' => '/painel' ), array(), array() )
		);
		preg_match( '/' . CookieSessionPort::COOKIE . '=([^;]+)/', $loginT->headers['Set-Cookie'] ?? '', $mt );
		$cT = array( CookieSessionPort::COOKIE => $mt[1] ?? '' );
		$tk->handle(
			new Request( 'POST', '/painel/autoridades', array(), array( 'action' => 'import_courier' ), $cT, array() )
		);
		$this->assertSame( 3, $tally->users->countByRole( 'editor' ) );

		$importId = $tally->persistence->tallyImports->create(
			array( 'source' => 'test', 'status' => 'imported', 'created_at' => gmdate( 'c' ) )
		);
		$parcela = json_decode( (string) file_get_contents( $this->root . '/courier/parcela-1.json' ), true );
		$this->assertIsArray( $parcela );
		$sub1 = $tk->handle(
			new Request(
				'POST',
				'/painel/parcelas',
				array(),
				array(
					'import_id'  => (string) $importId,
					'share_json' => (string) json_encode( $parcela ),
				),
				$cT,
				array()
			)
		);
		$this->assertStringContainsString( 'submetida', $sub1->body );

		$parcela2 = json_decode( (string) file_get_contents( $this->root . '/courier/parcela-2.json' ), true );
		$sub2 = $tk->handle(
			new Request(
				'POST',
				'/painel/parcelas',
				array(),
				array(
					'import_id'  => (string) $importId,
					'share_json' => (string) json_encode( $parcela2 ),
				),
				$cT,
				array()
			)
		);
		$this->assertStringContainsString( 'Limiar Shamir atingido', $sub2->body );
		$this->assertSame( 2, $tally->persistence->shareSubmissions->countByImport( $importId ) );
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
