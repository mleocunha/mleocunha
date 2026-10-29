<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit\Identity;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http\CookieSessionPort;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http\HttpKernel;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http\Request;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\NodeRuntime;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;
use RelataSoft\SecureElectionSuite\Painel\Domain\Identity\AccountStatus;

final class IamHttpSecurityTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/ve-iam-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root, 0700, true );
		putenv( 'VE_KEYGEN_NO_WORKER=1' );
		// CSRF real — sem bypass de teste.
		putenv( 'VE_HTTP_TEST_MODE' );
		unset( $_ENV['VE_HTTP_TEST_MODE'] );
	}

	protected function tearDown(): void {
		putenv( 'VE_KEYGEN_NO_WORKER' );
		$this->rrmdir( $this->root );
	}

	public function test_login_requires_csrf_and_blocks_suspended(): void {
		$plugin = dirname( __DIR__, 3 );
		$node   = NodeRuntime::create( SiteModes::VOTING, $this->root . '/v', 'teste', true );
		$kernel = new HttpKernel( $node, $plugin, 'pt-BR' );

		$fail = $kernel->handle(
			new Request( 'POST', '/login', array(), array( 'login' => 'admin', 'password' => 'PoC-Operador#99', 'next' => '/painel' ), array(), array() )
		);
		$this->assertStringContainsString( 'CSRF', $fail->body );

		$get  = $kernel->handle( new Request( 'GET', '/login', array(), array(), array(), array() ) );
		preg_match( '/name="csrf" value="([^"]+)"/', $get->body, $cm );
		$ok = $kernel->handle(
			new Request(
				'POST',
				'/login',
				array(),
				array(
					'login'    => 'admin',
					'password' => 'PoC-Operador#99',
					'next'     => '/painel',
					'csrf'     => $cm[1] ?? '',
				),
				array(),
				array()
			)
		);
		$this->assertSame( 302, $ok->status );
		preg_match( '/' . CookieSessionPort::COOKIE . '=([^;]+)/', $ok->headers['Set-Cookie'] ?? '', $m );
		$cookie = array( CookieSessionPort::COOKIE => $m[1] ?? '' );

		$admin = $node->users->findByLogin( 'admin' );
		$node->users->update( (int) $admin['id'], array( 'status' => AccountStatus::SUSPENDED ) );
		// Sessão antiga deve ser invalidada no próximo hydrate.
		$blocked = $kernel->handle( new Request( 'GET', '/painel', array(), array(), $cookie, array() ) );
		$this->assertSame( 302, $blocked->status );
		$this->assertStringContainsString( '/login', $blocked->headers['Location'] ?? '' );

		// Novo login com conta suspensa falha.
		$get2 = $kernel->handle( new Request( 'GET', '/login', array(), array(), array(), array() ) );
		preg_match( '/name="csrf" value="([^"]+)"/', $get2->body, $cm2 );
		$deny = $kernel->handle(
			new Request(
				'POST',
				'/login',
				array(),
				array(
					'login'    => 'admin',
					'password' => 'PoC-Operador#99',
					'csrf'     => $cm2[1] ?? '',
				),
				array(),
				array()
			)
		);
		$this->assertStringContainsString( 'incorretos', $deny->body );
	}

	public function test_mass_assignment_role_admin_rejected_on_cadastro(): void {
		putenv( 'VE_HTTP_TEST_MODE=1' );
		$plugin = dirname( __DIR__, 3 );
		$node   = NodeRuntime::create( SiteModes::VOTING, $this->root . '/v2', 'teste', true );
		$kernel = new HttpKernel( $node, $plugin, 'pt-BR' );
		$login  = $kernel->handle(
			new Request( 'POST', '/login', array(), array( 'login' => 'admin', 'password' => 'PoC-Operador#99', 'next' => '/painel' ), array(), array() )
		);
		preg_match( '/' . CookieSessionPort::COOKIE . '=([^;]+)/', $login->headers['Set-Cookie'] ?? '', $m );
		$cookie = array( CookieSessionPort::COOKIE => $m[1] ?? '' );
		$create = $kernel->handle(
			new Request(
				'POST',
				'/painel/cadastro',
				array(),
				array(
					'action'      => 'create_user',
					'login'       => 'evil',
					'email'       => 'evil@ex.test',
					'password'    => 'SenhaForte99!',
					'displayName' => 'Evil',
					'role'        => 'administrator',
				),
				$cookie,
				array()
			)
		);
		$this->assertStringContainsString( 'Papel não permitido', $create->body );
		$this->assertNull( $node->users->findByLogin( 'evil' ) );
		putenv( 'VE_HTTP_TEST_MODE' );
	}

	public function test_govbr_disabled_page(): void {
		putenv( 'VE_GOVBR_ENABLED' );
		putenv( 'VE_GOVBR_CLIENT_ID' );
		$plugin = dirname( __DIR__, 3 );
		$node   = NodeRuntime::create( SiteModes::VOTING, $this->root . '/v3', 'teste', true );
		$kernel = new HttpKernel( $node, $plugin, 'pt-BR' );
		$page   = $kernel->handle( new Request( 'GET', '/auth/govbr', array(), array(), array(), array() ) );
		$this->assertStringContainsString( 'não habilitada', $page->body );
		$this->assertStringContainsString( 'roteiro técnico', $page->body );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: array() as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $dir . '/' . $f;
			is_dir( $p ) ? $this->rrmdir( $p ) : @unlink( $p );
		}
		@rmdir( $dir );
	}
}
