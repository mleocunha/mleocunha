<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http;

use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Identity\FileJsonUserStore;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\NodeRuntime;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneySteps;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\UserRegistryRoles;
use RelataSoft\SecureElectionSuite\Painel\Domain\Authorities\AuthoritiesDirectorySync;
use RelataSoft\SecureElectionSuite\Painel\Domain\Authorities\AuthoritiesPackage;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ElGamal;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\HomomorphicTally;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\PrimeGenerator;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ShamirSecretSharing;
use RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll\RsvFormat;
use RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll\RsvImporter;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\MaterialCourier;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\PublicKeyPackage;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Journey\InMemoryJourneyRouteResolver;

/**
 * Kernel HTTP standalone — login, painel (3 modos E3), /voto, RSV, courier.
 */
final class HttpKernel {

	/** Tamanhos ElGamal permitidos (bits de p) — alinhado ao KA WordPress legado. */
	private const ALLOWED_KEY_SIZES = array( 512, 1024, 2048, 3072, 4096 );

	private CookieSessionPort $session;
	private HtmlShell $shell;
	private CatalogI18n $i18n;
	private string $packageRoot;
	private string $flash = '';

	public function __construct(
		private readonly NodeRuntime $node,
		string $packageRoot,
		?string $acceptLanguage = null,
	) {
		$this->packageRoot = rtrim( $packageRoot, '/\\' );
		// Interface do produto: PT-BR por omissão (infinitivos). VE_LOCALE pode forçar outro catálogo.
		$envLocale = (string) ( getenv( 'VE_LOCALE' ) ?: '' );
		$locale    = '' !== $envLocale
			? CatalogI18n::fromAcceptLanguage( $envLocale, 'pt_BR' )
			: 'pt_BR';
		unset( $acceptLanguage ); // Accept-Language do browser não muda o padrão do produto.
		$this->i18n = new CatalogI18n( $this->packageRoot . '/languages/catalogs', $locale );
		$users = $this->node->users;
		if ( ! $users instanceof FileJsonUserStore ) {
			throw new \RuntimeException( 'HTTP kernel requires durable FileJsonUserStore (NodeRuntime durable=true).' );
		}
		$this->session = new CookieSessionPort( $users->documentStore(), $users );
		$this->shell   = new HtmlShell(
			$this->i18n,
			$this->node->mode->getMode(),
			SiteModes::label( $this->node->mode->getMode() )
		);
		$this->ensureBootstrapAdmin( $users );
	}

	public function handle( Request $req ): Response {
		$this->session->hydrateFromCookie( $req->cookies[ CookieSessionPort::COOKIE ] ?? null );

		$path = '/' . trim( $req->path, '/' );
		if ( '/' === $path ) {
			$path = '/painel';
		}

		// Static assets (reuse Painel CSS/JS/brand).
		if ( str_starts_with( $path, '/assets/' ) ) {
			return $this->serveAsset( $path );
		}

		if ( '/login' === $path ) {
			return $this->login( $req );
		}
		if ( '/logout' === $path ) {
			return $this->logout( $req );
		}

		// Journey (voting mode) — requires authenticated voter or open for booth after login.
		$step = ( new InMemoryJourneyRouteResolver() )->resolveStep( $path );
		if ( null !== $step ) {
			return $this->journey( $req, $step );
		}

		if ( ! $this->session->isAuthenticated() ) {
			return Response::redirect( '/login?next=' . rawurlencode( $path ) );
		}

		if ( preg_match( '#^/painel/chave/(\d+)(\.json)?$#', $path, $m ) ) {
			return $this->chavePublica( $req, (int) $m[1], isset( $m[2] ) && '' !== $m[2] );
		}

		return match ( true ) {
			'/painel' === $path => $this->painelHome(),
			'/painel/cadastro' === $path => $this->cadastro( $req ),
			'/painel/autoridades' === $path => $this->autoridades( $req ),
			'/painel/keygen' === $path => $this->keygen( $req ),
			'/painel/keygen/status' === $path => $this->keygenStatus( $req ),
			'/painel/keygen/tick' === $path => $this->keygenTick( $req ),
			'/painel/keygen/cancel' === $path => $this->keygenCancel( $req ),
			'/painel/parcelas' === $path => $this->parcelas( $req ),
			'/painel/courier' === $path => $this->courier( $req ),
			'/painel/eleicoes' === $path => $this->eleicoes(),
			'/painel/importar' === $path => $this->tallyImport( $req ),
			'/painel/certificar' === $path => $this->certificar( $req ),
			default => Response::html( $this->shell->render( '404', '<div class="ve-card"><h1>404</h1><p class="ve-muted">Rota não encontrada.</p></div>' ), 404 ),
		};
	}

	private function ensureBootstrapAdmin( FileJsonUserStore $users ): void {
		if ( $users->countByRole( 'administrator' ) > 0 ) {
			return;
		}
		$login = getenv( 'VE_ADMIN_LOGIN' ) ?: 'admin';
		$pass  = getenv( 'VE_ADMIN_PASS' ) ?: 'AdminPoC1!';
		$users->create(
			array(
				'login'       => $login,
				'email'       => $login . '@local.invalid',
				'password'    => $pass,
				'displayName' => 'Administrador',
				'role'        => 'administrator',
			)
		);
	}

	private function nav(): array {
		$mode = $this->node->mode->getMode();
		$items = array(
			array( 'href' => '/painel', 'label' => 'Painel' ),
		);
		if ( SiteModes::VOTING === $mode ) {
			$items[] = array( 'href' => '/painel/cadastro', 'label' => 'Cadastro' );
			$items[] = array( 'href' => '/painel/autoridades', 'label' => 'Autoridades' );
			$items[] = array( 'href' => '/painel/eleicoes', 'label' => 'Eleições' );
			$items[] = array( 'href' => '/voto', 'label' => 'Voto' );
			$items[] = array( 'href' => '/painel/courier', 'label' => 'Courier' );
		}
		if ( SiteModes::KEY_AUTHORITY === $mode ) {
			$items[] = array( 'href' => '/painel/autoridades', 'label' => 'Autoridades' );
			$items[] = array( 'href' => '/painel/keygen', 'label' => 'Chaves' );
			$items[] = array( 'href' => '/painel/courier', 'label' => 'Courier' );
		}
		if ( SiteModes::TALLYING === $mode ) {
			$items[] = array( 'href' => '/painel/autoridades', 'label' => 'Autoridades' );
			$items[] = array( 'href' => '/painel/importar', 'label' => 'Importar' );
			$items[] = array( 'href' => '/painel/parcelas', 'label' => 'Parcelas' );
			$items[] = array( 'href' => '/painel/certificar', 'label' => 'Certificar' );
			$items[] = array( 'href' => '/painel/courier', 'label' => 'Courier' );
		}
		$items[] = array( 'href' => '/logout', 'label' => 'Sair' );
		return $items;
	}

	private function page( string $title, string $body ): Response {
		return Response::html( $this->shell->render( $title, $body, $this->nav(), $this->flash ) );
	}

	private function login( Request $req ): Response {
		$error = '';
		if ( 'POST' === $req->method ) {
			$login = trim( $req->input( 'login' ) );
			$pass  = $req->input( 'password' );
			$users = $this->node->users;
			$user  = $users instanceof FileJsonUserStore ? $users->verifyPassword( $login, $pass ) : null;
			if ( null === $user && method_exists( $users, 'findByLogin' ) ) {
				// Fallback InMemory plain hashes (tests).
				$row = $users->findByLogin( $login );
				if ( $row && FileJsonUserStore::passwordMatches( (string) ( $row['passwordHash'] ?? '' ), $pass ) ) {
					$user = $row;
				}
			}
			if ( null !== $user ) {
				$token = $this->session->login( (int) $user['id'] );
				$next  = $req->input( 'next', $req->query( 'next', '/painel' ) );
				if ( ! str_starts_with( $next, '/' ) ) {
					$next = '/painel';
				}
				$res = Response::redirect( $next );
				// Attach Set-Cookie via custom header merge — Response is immutable; rebuild.
				return new Response(
					'',
					302,
					array(
						'Location'   => $next,
						'Set-Cookie' => CookieSessionPort::COOKIE . '=' . $token . '; Path=/; HttpOnly; SameSite=Lax',
					)
				);
			}
			$error = 'Login ou senha incorrectos.';
		}
		$body = '<div class="ve-card"><h1>Entrar</h1>'
			. ( $error ? '<p class="ve-muted">' . htmlspecialchars( $error, ENT_QUOTES, 'UTF-8' ) . '</p>' : '' )
			. '<form method="post" action="/login"><input type="hidden" name="next" value="'
			. htmlspecialchars( $req->query( 'next', '/painel' ), ENT_QUOTES, 'UTF-8' ) . '" />'
			. '<label class="ve-field"><span>Login</span><input name="login" required autocomplete="username" /></label>'
			. '<label class="ve-field"><span>Senha</span><input type="password" name="password" required autocomplete="current-password" /></label>'
			. '<div class="ve-actions"><button type="submit">Entrar</button></div></form>'
			. '<p class="ve-muted">Modo: ' . htmlspecialchars( SiteModes::label( $this->node->mode->getMode() ), ENT_QUOTES, 'UTF-8' ) . '</p>'
			. '</div>';
		return Response::html( $this->shell->render( 'Login', $body ) );
	}

	private function logout( Request $req ): Response {
		$this->session->logout( $req->cookies[ CookieSessionPort::COOKIE ] ?? null );
		// Resposta HTML mínima + redirect: evita parecer «morto» se o cliente esperar corpo.
		return new Response(
			'<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"/><meta http-equiv="refresh" content="0;url=/login"/><title>Sair</title></head>'
			. '<body><p>Sessão terminada. <a href="/login">Entrar</a></p></body></html>',
			302,
			array(
				'Location'   => '/login',
				'Set-Cookie' => CookieSessionPort::COOKIE . '=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax',
				'Content-Type' => 'text/html; charset=UTF-8',
			)
		);
	}

	private function painelHome(): Response {
		$mode = $this->node->mode->getMode();
		$cards = '';
		if ( SiteModes::VOTING === $mode ) {
			$cards .= $this->card( 'Cadastro eleitoral', 'Importar .rsv e listar papéis.', '/painel/cadastro' );
			$cards .= $this->card( 'Autoridades eleitorais', 'Importar ou acompanhar autoridades (validade jurídica da eleição).', '/painel/autoridades' );
			$cards .= $this->card( 'Eleições', 'Ver eleições neste nó.', '/painel/eleicoes' );
			$cards .= $this->card( 'Jornada /voto', 'Boas-vindas, cabine e obrigado.', '/voto' );
			$cards .= $this->card( 'Courier', 'Importar chave pública / exportar material de voto.', '/painel/courier' );
		} elseif ( SiteModes::KEY_AUTHORITY === $mode ) {
			$cards .= $this->card( 'Autoridades eleitorais', 'Cadastrar e exportar autoridades antes de atribuir parcelas Shamir.', '/painel/autoridades' );
			$cards .= $this->card( 'Chaves', 'Gerar chave, atribuir parcelas e visualizar a chave pública.', '/painel/keygen' );
			$cards .= $this->card( 'Courier', 'Exportar chave pública e parcelas.', '/painel/courier' );
		} else {
			$cards .= $this->card( 'Autoridades eleitorais', 'Importar autoridades para subirem parcelas até ao limiar Shamir.', '/painel/autoridades' );
			$cards .= $this->card( 'Importar apuração', 'Importar material de voto do courier.', '/painel/importar' );
			$cards .= $this->card( 'Parcelas Shamir', 'Submeter parcelas até atingir o limiar.', '/painel/parcelas' );
			$cards .= $this->card( 'Certificar', 'Registar certificação da apuração.', '/painel/certificar' );
			$cards .= $this->card( 'Courier', 'Pasta de material entre nós.', '/painel/courier' );
		}
		$user = $this->node->users->findById( $this->session->currentUserId() );
		$who  = htmlspecialchars( (string) ( $user['login'] ?? '' ), ENT_QUOTES, 'UTF-8' );
		$body = '<div class="ve-card"><h1>Painel de Controle Eleitoral</h1>'
			. '<p class="ve-muted">Sessão: <code>' . $who . '</code> · nó standalone</p></div>'
			. $cards;
		return $this->page( 'Painel', $body );
	}

	private function card( string $title, string $desc, string $href ): string {
		return '<div class="ve-card"><h2>' . htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ) . '</h2>'
			. '<p class="ve-muted">' . htmlspecialchars( $desc, ENT_QUOTES, 'UTF-8' ) . '</p>'
			. '<div class="ve-actions"><a href="' . htmlspecialchars( $href, ENT_QUOTES, 'UTF-8' ) . '">Abrir</a></div></div>';
	}

	private function cadastro( Request $req ): Response {
		$this->node->requireMode( SiteModes::VOTING );
		$msg = '';
		if ( 'POST' === $req->method && isset( $req->files['rsv'] ) ) {
			$file = $req->files['rsv'];
			$tmp  = (string) ( $file['tmp_name'] ?? '' );
			if ( is_uploaded_file( $tmp ) || ( is_readable( $tmp ) && '' !== $tmp ) ) {
				$text = (string) file_get_contents( $tmp );
				$res  = RsvImporter::importText( $text, $this->node->users, true );
				$msg  = sprintf(
					'Importação: criados %d, actualizados %d, ignorados %d, erros %d.',
					$res['created'],
					$res['updated'],
					$res['skipped'],
					count( $res['errors'] )
				);
				if ( $res['errors'] ) {
					$msg .= ' Primeiro erro (linha ' . $res['errors'][0]['line'] . '): ' . $res['errors'][0]['message'];
				}
			} else {
				$msg = 'Falha no upload do .rsv.';
			}
		}

		$rows = '';
		foreach ( array( 'subscriber', 've_auditor', 'editor', 'administrator', 've_gestor' ) as $role ) {
			$count = $this->node->users->countByRole( $role );
			$list  = $this->node->users->listByRole( $role, 0, 25 );
			$rows .= '<h2>' . htmlspecialchars( $role, ENT_QUOTES, 'UTF-8' ) . ' (' . $count . ')</h2><table class="ve-table"><thead><tr><th>Nome</th><th>Login</th><th>E-mail</th></tr></thead><tbody>';
			foreach ( $list as $u ) {
				$rows .= '<tr><td>' . htmlspecialchars( (string) $u['displayName'], ENT_QUOTES, 'UTF-8' )
					. '</td><td><code>' . htmlspecialchars( (string) $u['login'], ENT_QUOTES, 'UTF-8' )
					. '</code></td><td>' . htmlspecialchars( (string) $u['email'], ENT_QUOTES, 'UTF-8' ) . '</td></tr>';
			}
			if ( ! $list ) {
				$rows .= '<tr><td colspan="3" class="ve-muted">—</td></tr>';
			}
			$rows .= '</tbody></table>';
		}

		$body = '<div class="ve-card"><h1>Cadastro Eleitoral</h1>'
			. ( $msg ? '<p class="ve-muted">' . htmlspecialchars( $msg, ENT_QUOTES, 'UTF-8' ) . '</p>' : '' )
			. '<p class="ve-muted">Formato: <code>' . htmlspecialchars( RsvFormat::headerLine(), ENT_QUOTES, 'UTF-8' ) . '</code></p>'
			. '<form method="post" enctype="multipart/form-data" action="/painel/cadastro">'
			. '<label class="ve-field"><span>Arquivo .rsv</span><input type="file" name="rsv" accept=".rsv,text/plain" required /></label>'
			. '<div class="ve-actions"><button type="submit">Importar RSV</button></div></form></div>'
			. '<div class="ve-card">' . $rows . '</div>';
		return $this->page( 'Cadastro', $body );
	}

	private function autoridades( Request $req ): Response {
		$mode = $this->node->mode->getMode();
		$msg  = '';
		$users = $this->node->users;
		if ( ! $users instanceof FileJsonUserStore ) {
			throw new \RuntimeException( 'Autoridades require FileJsonUserStore.' );
		}
		$courierDir = dirname( $this->node->dataDir ) . '/courier';

		if ( 'POST' === $req->method ) {
			$action = $req->input( 'action', 'create' );
			if ( 'export' === $action && SiteModes::KEY_AUTHORITY === $mode ) {
				$path = $this->exportAuthoritiesToCourier( $courierDir );
				$msg  = 'Pacote exportado para courier: ' . basename( $path );
			} elseif ( 'import_courier' === $action && SiteModes::KEY_AUTHORITY !== $mode ) {
				$file = $courierDir . '/' . AuthoritiesDirectorySync::COURIER_FILE;
				$msg  = $this->importAuthoritiesFromFile( $users, $file );
			} elseif ( 'import_upload' === $action && SiteModes::KEY_AUTHORITY !== $mode ) {
				$tmp = (string) ( $req->files['package']['tmp_name'] ?? '' );
				$msg = ( is_readable( $tmp ) && '' !== $tmp )
					? $this->importAuthoritiesFromFile( $users, $tmp )
					: 'Falha no upload do pacote de autoridades.';
			} else {
				$login = trim( $req->input( 'login' ) );
				$email = trim( $req->input( 'email' ) );
				$name  = trim( $req->input( 'displayName', $login ) );
				$pass  = $req->input( 'password' );
				if ( '' === $login || '' === $email || '' === $pass ) {
					$msg = 'Login, e-mail e senha são obrigatórios.';
				} else {
					$res = $users->create(
						array(
							'login'       => $login,
							'email'       => $email,
							'password'    => $pass,
							'displayName' => '' !== $name ? $name : $login,
							'role'        => UserRegistryRoles::ROLE_OFFICIAL,
						)
					);
					$msg = ! empty( $res['ok'] )
						? 'Autoridade eleitoral cadastrada (#' . (int) ( $res['id'] ?? 0 ) . ').'
						: ( 'Falha: ' . (string) ( $res['error'] ?? 'erro' ) );
				}
			}
		}

		$list = $users->listByRole( UserRegistryRoles::ROLE_OFFICIAL );
		$rows = '';
		foreach ( $list as $u ) {
			$rows .= '<tr><td>' . (int) $u['id'] . '</td><td>'
				. htmlspecialchars( (string) $u['displayName'], ENT_QUOTES, 'UTF-8' ) . '</td><td><code>'
				. htmlspecialchars( (string) $u['login'], ENT_QUOTES, 'UTF-8' ) . '</code></td><td>'
				. htmlspecialchars( (string) $u['email'], ENT_QUOTES, 'UTF-8' ) . '</td></tr>';
		}
		if ( '' === $rows ) {
			$rows = '<tr><td colspan="4" class="ve-muted">Nenhuma autoridade neste nó.</td></tr>';
		}

		$lead = match ( $mode ) {
			SiteModes::KEY_AUTHORITY => 'Cadastrar quem receberá as parcelas Shamir. Exportar o pacote para o courier para provisionar voting e tallying.',
			SiteModes::VOTING => 'As autoridades acompanham a eleição neste sítio; a validade jurídica fica comprometida sem o seu acompanhamento. Preferir importar o pacote exportado pelo nó de chaves.',
			default => 'Sem autoridades neste nó, ninguém sobe parcelas Shamir — o limiar de reconstrução não é atingível. Importar o pacote do KA e pedir a cada autoridade que entre e submeta a sua parcela.',
		};

		$extra = '';
		if ( SiteModes::KEY_AUTHORITY === $mode ) {
			$extra = '<form method="post" action="/painel/autoridades" style="margin-top:1rem">'
				. '<input type="hidden" name="action" value="export" />'
				. '<div class="ve-actions"><button type="submit">Exportar autoridades → courier/'
				. htmlspecialchars( AuthoritiesDirectorySync::COURIER_FILE, ENT_QUOTES, 'UTF-8' )
				. '</button></div></form>';
		} else {
			$extra = '<div class="ve-card" style="margin-top:1rem"><h2>Importar pacote</h2>'
				. '<form method="post" action="/painel/autoridades">'
				. '<input type="hidden" name="action" value="import_courier" />'
				. '<div class="ve-actions"><button type="submit">Importar '
				. htmlspecialchars( AuthoritiesDirectorySync::COURIER_FILE, ENT_QUOTES, 'UTF-8' )
				. ' do courier</button></div></form>'
				. '<form method="post" enctype="multipart/form-data" action="/painel/autoridades" style="margin-top:0.75rem">'
				. '<input type="hidden" name="action" value="import_upload" />'
				. '<label class="ve-field"><span>Ficheiro JSON</span><input type="file" name="package" accept=".json,application/json" required /></label>'
				. '<div class="ve-actions"><button type="submit">Importar upload</button></div></form></div>';
		}

		$body = '<div class="ve-card"><h1>Autoridades eleitorais</h1>'
			. '<p class="ve-muted">' . htmlspecialchars( $lead, ENT_QUOTES, 'UTF-8' ) . '</p>'
			. ( $msg ? '<p class="ve-muted">' . htmlspecialchars( $msg, ENT_QUOTES, 'UTF-8' ) . '</p>' : '' )
			. '<form method="post" action="/painel/autoridades">'
			. '<input type="hidden" name="action" value="create" />'
			. '<label class="ve-field"><span>Login</span><input name="login" required autocomplete="off" /></label>'
			. '<label class="ve-field"><span>Nome</span><input name="displayName" /></label>'
			. '<label class="ve-field"><span>E-mail</span><input type="email" name="email" required /></label>'
			. '<label class="ve-field"><span>Senha</span><input type="password" name="password" required autocomplete="new-password" /></label>'
			. '<div class="ve-actions"><button type="submit">Cadastrar autoridade neste nó</button>'
			. ( SiteModes::KEY_AUTHORITY === $mode
				? '<a class="secondary" href="/painel/keygen">Ir à geração de chave</a>'
				: ( SiteModes::TALLYING === $mode
					? '<a class="secondary" href="/painel/parcelas">Ir às parcelas</a>'
					: '' ) )
			. '</div></form>'
			. $extra
			. '</div>'
			. '<div class="ve-card"><h2>Neste nó (' . count( $list ) . ')</h2>'
			. '<table class="ve-table"><thead><tr><th>ID</th><th>Nome</th><th>Login</th><th>E-mail</th></tr></thead><tbody>'
			. $rows . '</tbody></table></div>';
		return $this->page( 'Autoridades', $body );
	}

	private function exportAuthoritiesToCourier( string $courierDir ): string {
		if ( ! is_dir( $courierDir ) ) {
			mkdir( $courierDir, 0700, true );
		}
		$officials = $this->node->users->listByRole( UserRegistryRoles::ROLE_OFFICIAL );
		$shareMeta = array();
		foreach ( $this->node->persistence->keys->listActive() as $k ) {
			$kid = (int) $k['id'];
			foreach ( $this->node->persistence->shares->listByKey( $kid ) as $s ) {
				$uid = (int) ( $s['official_user_id'] ?? 0 );
				if ( $uid <= 0 ) {
					continue;
				}
				$shareMeta[ $uid ] = array(
					'share_index' => (int) ( $s['share_index'] ?? 0 ),
					'key_id'      => $kid,
					'threshold_t' => (int) ( $k['threshold'] ?? $s['threshold_t'] ?? 0 ),
					'total_n'     => (int) ( $k['total_shares'] ?? $s['total_n'] ?? 0 ),
				);
			}
		}
		$pkg  = AuthoritiesDirectorySync::buildPackage(
			$officials,
			$shareMeta,
			SiteModes::KEY_AUTHORITY,
			(string) ( getenv( 'VE_PUBLIC_BASE' ) ?: '' )
		);
		$path = $courierDir . '/' . AuthoritiesDirectorySync::COURIER_FILE;
		file_put_contents( $path, AuthoritiesPackage::toJson( $pkg ) );
		return $path;
	}

	private function importAuthoritiesFromFile( FileJsonUserStore $users, string $file ): string {
		if ( ! is_readable( $file ) ) {
			return 'Ficheiro inacessível: ' . basename( $file );
		}
		$pkg = AuthoritiesPackage::fromJson( (string) file_get_contents( $file ) );
		if ( null === $pkg ) {
			return 'Pacote inválido ou checksum incorrecto.';
		}
		$res = AuthoritiesDirectorySync::importPackage( $users, $pkg );
		return sprintf(
			'Importação: criados %d, actualizados %d, ignorados %d, erros %d.',
			$res['created'],
			$res['updated'],
			$res['skipped'],
			count( $res['errors'] )
		) . ( $res['errors'] ? ' ' . $res['errors'][0] : '' );
	}

	private function keygen( Request $req ): Response {
		$this->node->requireMode( SiteModes::KEY_AUTHORITY );
		$msg       = trim( $req->query( 'msg', '' ) );
		$officials = $this->node->users->listByRole( UserRegistryRoles::ROLE_OFFICIAL );
		$officialN = count( $officials );

		if ( 'POST' === $req->method && 'delete_key' === $req->input( 'action' ) ) {
			$del = $this->executeDeleteKey( $req );
			$msg = (string) ( $del['message'] ?? '' );
			if ( $this->wantsJson( $req ) ) {
				return Response::json(
					array(
						'ok'      => ! empty( $del['ok'] ),
						'message' => $msg,
						'key_id'  => $del['key_id'] ?? null,
					),
					! empty( $del['ok'] ) ? 200 : 422
				);
			}
		} elseif ( 'POST' === $req->method ) {
			$result = $this->startKeygenJob( $req, $officials, $officialN );
			$msg    = (string) ( $result['message'] ?? '' );
			if ( $this->wantsJson( $req ) ) {
				return Response::json( $result, ! empty( $result['ok'] ) ? 200 : 422 );
			}
		}

		$jobStatus = $this->node->jobs->keygen->status();
		$jobActive = ! empty( $jobStatus['active'] );
		if ( $jobActive && '' === $msg ) {
			$msg = (string) ( $jobStatus['message'] ?? 'Geração em curso…' );
		}

		$checkboxes = '';
		foreach ( $officials as $o ) {
			$id = (int) $o['id'];
			$checkboxes .= '<label class="ve-field" style="display:flex;gap:0.5rem;align-items:center;max-width:none">'
				. '<input type="checkbox" name="official_ids[]" value="' . $id . '" />'
				. '<span><strong>' . htmlspecialchars( (string) $o['displayName'], ENT_QUOTES, 'UTF-8' )
				. '</strong> <code>' . htmlspecialchars( (string) $o['login'], ENT_QUOTES, 'UTF-8' ) . '</code></span></label>';
		}
		if ( '' === $checkboxes ) {
			$checkboxes = '<p class="ve-muted">Nenhuma autoridade cadastrada. '
				. '<a href="/painel/autoridades">Cadastrar autoridades eleitorais</a> primeiro.</p>';
		}

		$list = $this->renderActiveKeysTable();
		$canGenerate = $officialN >= 2 && ! $jobActive;
		$progressUi = $this->keygenProgressMarkup( $jobActive, $jobStatus );
		$bitsSelect = $this->renderKeySizeSelect( 2048 );
		$cancelBtn = $jobActive
			? '<div class="ve-actions"><button type="button" id="ve-keygen-cancel" class="secondary">Cancelar geração</button></div>'
			: '';
		$body = '<div class="ve-card"><h1>Autoridade de chaves</h1>'
			. '<p class="ve-muted">O cadastramento de autoridades eleitorais é obrigatório antes da atribuição de parcelas Shamir.</p>'
			. '<p class="ve-muted">A geração corre em <strong>background no servidor</strong> (worker). Pode usar <a href="/logout">Sair</a> '
			. 'e voltar noutro dispositivo — o andamento continua em <code>/painel/keygen</code>.</p>'
			. '<p id="ve-keygen-flash" class="ve-muted"' . ( $msg ? '' : ' hidden' ) . '>'
			. htmlspecialchars( $msg, ENT_QUOTES, 'UTF-8' ) . '</p>'
			. $progressUi
			. $cancelBtn
			. ( $canGenerate
				? '<form id="ve-keygen-form" method="post" action="/painel/keygen">'
					. '<label class="ve-field"><span>Rótulo (nome legível)</span>'
					. '<input name="key_title" required maxlength="120" placeholder="Ex.: Eleição municipal 2026 — urnas" value="'
					. htmlspecialchars( 'Eleição ' . gmdate( 'Y-m-d H:i' ), ENT_QUOTES, 'UTF-8' ) . '" /></label>'
					. '<label class="ve-field"><span>Tamanho da chave (bits)</span>' . $bitsSelect . '</label>'
					. '<p class="ve-muted">Geração real ElGamal (GMP + primo seguro). 2048+ pode demorar minutos; 4096 horas em máquinas lentas. 512 só laboratório. Pode sair durante a geração.</p>'
					. '<label class="ve-field"><span>Limiar (t)</span><input name="threshold" value="2" /></label>'
					. '<label class="ve-field"><span>Parcelas (n)</span><input name="shares" value="' . max( 3, $officialN ) . '" /></label>'
					. '<h2>Autoridades que recebem parcelas</h2>'
					. '<p class="ve-muted">Selecionar exatamente n contas (papel autoridade).</p>'
					. $checkboxes
					. '<div class="ve-actions">'
					. '<button type="submit" id="ve-keygen-submit">Gerar chave + atribuir parcelas</button>'
					. '</div></form>'
				: ( $jobActive
					? '<p class="ve-muted">Geração em curso — formulário bloqueado até concluir ou cancelar.</p>'
					: '<p class="ve-muted">Cadastrar pelo menos duas autoridades em '
						. '<a href="/painel/autoridades">/painel/autoridades</a> antes de gerar a chave.</p>' ) )
			. '</div>'
			. '<div class="ve-card"><h2>Chaves ativas</h2><div id="ve-keys-table">' . $list . '</div></div>'
			. $this->keygenProgressScript( $jobActive );
		return $this->page( 'Keygen', $body );
	}

	private function keygenStatus( Request $req ): Response {
		$this->node->requireMode( SiteModes::KEY_AUTHORITY );
		$status = $this->node->jobs->keygen->status();
		if ( ! empty( $status['active'] ) ) {
			// Avanço oportunista se o worker morreu (admin com sessão); o worker principal não precisa disto.
			$status = $this->node->jobs->keygen->tick();
		}
		if ( ! empty( $status['stage'] ) && 'complete' === $status['stage'] ) {
			$this->exportAuthoritiesToCourier( dirname( $this->node->dataDir ) . '/courier' );
			$status['keys_html'] = $this->renderActiveKeysTable();
		}
		return Response::json( $status );
	}

	private function keygenTick( Request $req ): Response {
		$this->node->requireMode( SiteModes::KEY_AUTHORITY );
		if ( 'POST' !== $req->method ) {
			return Response::json( array( 'ok' => false, 'message' => 'POST necessário.' ), 405 );
		}
		$status = $this->node->jobs->keygen->tick();
		if ( ! empty( $status['stage'] ) && 'complete' === $status['stage'] ) {
			$this->exportAuthoritiesToCourier( dirname( $this->node->dataDir ) . '/courier' );
			$status['keys_html'] = $this->renderActiveKeysTable();
		}
		return Response::json( $status );
	}

	private function keygenCancel( Request $req ): Response {
		$this->node->requireMode( SiteModes::KEY_AUTHORITY );
		if ( 'POST' !== $req->method ) {
			return Response::json( array( 'ok' => false, 'message' => 'POST necessário.' ), 405 );
		}
		return Response::json( $this->node->jobs->keygen->cancel() );
	}

	private function wantsJson( Request $req ): bool {
		$accept = strtolower( (string) ( $req->server['HTTP_ACCEPT'] ?? '' ) );
		$xrw    = strtolower( (string) ( $req->server['HTTP_X_REQUESTED_WITH'] ?? '' ) );
		return str_contains( $accept, 'application/json' )
			|| 'xmlhttprequest' === $xrw
			|| '1' === $req->query( 'ajax' )
			|| '1' === $req->input( 'ajax' );
	}

	/**
	 * @param list<array<string,mixed>> $officials
	 * @return array<string,mixed>
	 */
	private function startKeygenJob( Request $req, array $officials, int $officialN ): array {
		$bitsReq = (int) $req->input( 'bits', '2048' );
		if ( ! in_array( $bitsReq, self::ALLOWED_KEY_SIZES, true ) ) {
			return array(
				'ok'      => false,
				'active'  => false,
				'message' => 'Tamanho de chave inválido. Usar: ' . implode( ', ', self::ALLOWED_KEY_SIZES ) . ' bits.',
			);
		}
		$th = max( 2, (int) $req->input( 'threshold', '2' ) );
		$n  = max( $th, (int) $req->input( 'shares', (string) max( 3, $officialN ) ) );
		$title = trim( $req->input( 'key_title', '' ) );
		if ( '' === $title ) {
			$title = 'Eleição ' . gmdate( 'Y-m-d H:i' );
		}
		$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 120 ) : substr( $title, 0, 120 );
		$techLabel = 've-' . gmdate( 'Ymd-His' );
		$rawIds = $req->inputList( 'official_ids' );
		$selected = array();
		foreach ( $rawIds as $id ) {
			$uid = (int) $id;
			if ( $uid > 0 ) {
				$selected[ $uid ] = $uid;
			}
		}
		$selected = array_values( $selected );

		if ( $officialN < $n ) {
			return array(
				'ok'      => false,
				'active'  => false,
				'message' => "É necessário cadastrar pelo menos {$n} autoridades eleitorais antes de gerar {$n} parcelas. Há {$officialN} cadastrada(s).",
			);
		}
		if ( count( $selected ) !== $n ) {
			return array(
				'ok'      => false,
				'active'  => false,
				'message' => "Selecionar exatamente {$n} autoridades para receber as parcelas (selecionadas: " . count( $selected ) . ').',
			);
		}
		$byId = array();
		foreach ( $officials as $o ) {
			$byId[ (int) $o['id'] ] = $o;
		}
		foreach ( $selected as $uid ) {
			if ( ! isset( $byId[ $uid ] ) ) {
				return array(
					'ok'      => false,
					'active'  => false,
					'message' => 'Uma das autoridades seleccionadas não é válida.',
				);
			}
		}

		$status = $this->node->jobs->keygen->start(
			array(
				'bits'        => $bitsReq,
				'threshold_t' => $th,
				'total_n'     => $n,
				'officials'   => $selected,
				'label'       => $title,
				'key_title'   => $title,
				'key_label'   => $techLabel,
			)
		);
		if ( empty( $status['ok'] ) && empty( $status['active'] ) ) {
			return $status;
		}
		$this->spawnKeygenWorker();
		$status['message'] = sprintf(
			'Geração de %d bits iniciada em background (job %s). Pode sair — o progresso fica em /painel/keygen.',
			$bitsReq,
			(string) ( $status['job_id'] ?? '' )
		);
		$status['ok'] = true;
		return $status;
	}

	private function spawnKeygenWorker(): void {
		if ( '1' === (string) ( getenv( 'VE_KEYGEN_NO_WORKER' ) ?: '' ) ) {
			return;
		}
		$php  = PHP_BINARY !== '' ? PHP_BINARY : 'php';
		$bin  = $this->packageRoot . '/bin/ve-keygen-worker';
		$data = $this->node->dataDir;
		$mode = SiteModes::KEY_AUTHORITY;
		$log  = rtrim( $data, '/\\' ) . '/keygen-worker.log';
		$cmd  = sprintf(
			'nohup %s %s --mode=%s --data=%s >> %s 2>&1 &',
			escapeshellarg( $php ),
			escapeshellarg( $bin ),
			escapeshellarg( $mode ),
			escapeshellarg( $data ),
			escapeshellarg( $log )
		);
		exec( $cmd );
	}

	/**
	 * @return list<int>
	 */
	private function allowedKeySizes(): array {
		return self::ALLOWED_KEY_SIZES;
	}

	private function renderKeySizeSelect( int $selected ): string {
		$labels = array(
			512  => '512 — só laboratório / testes',
			1024 => '1024 — desenvolvimento',
			2048 => '2048 — mínimo recomendado',
			3072 => '3072 — mais forte (pode demorar minutos)',
			4096 => '4096 — mais forte (pode demorar horas; corre em background)',
		);
		$html = '<select name="bits" id="ve-key-bits" required>';
		foreach ( $this->allowedKeySizes() as $size ) {
			$sel   = $size === $selected ? ' selected' : '';
			$label = $labels[ $size ] ?? ( (string) $size . ' bits' );
			$html .= '<option value="' . $size . '"' . $sel . '>'
				. htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' ) . '</option>';
		}
		$html .= '</select>';
		return $html;
	}

	/**
	 * Tamanho em bits: campo gravado ou comprimento de p (chaves antigas sem key_size).
	 *
	 * @param array<string,mixed> $k
	 */
	private function resolveKeySizeBits( array $k ): int {
		$stored = (int) ( $k['key_size'] ?? 0 );
		if ( $stored >= 512 ) {
			return $stored;
		}
		$p = trim( (string) ( $k['public_p'] ?? '' ) );
		if ( '' === $p || ! ctype_digit( $p ) ) {
			return 0;
		}
		try {
			return BigInt::bitLength( BigInt::fromDecimalString( $p ) );
		} catch ( \Throwable ) {
			return 0;
		}
	}

	private function formatKeySizeLabel( int $bits ): string {
		if ( $bits < 1 ) {
			return '—';
		}
		return $bits . ' bits';
	}

	private function renderActiveKeysTable(): string {
		$keys = $this->node->persistence->keys->listActive();
		$confirmWord = $this->i18n->destructiveConfirmWord();
		$confirmEsc  = htmlspecialchars( $confirmWord, ENT_QUOTES, 'UTF-8' );
		$list = '<table class="ve-table"><thead><tr><th>ID</th><th>Rótulo</th><th>Tamanho</th><th>Técnico</th><th>t/n</th><th>Ações</th></tr></thead><tbody>';
		foreach ( $keys as $k ) {
			$kid   = (int) $k['id'];
			$human = (string) ( $k['display_name'] ?? '' );
			if ( '' === $human ) {
				$human = (string) ( $k['key_label'] ?? 'Chave #' . $kid );
			}
			$tech = (string) ( $k['key_label'] ?? '' );
			$bits = $this->resolveKeySizeBits( $k );
			$bitsCell = $bits > 0
				? '<strong class="ve-key-bits">' . htmlspecialchars( $this->formatKeySizeLabel( $bits ), ENT_QUOTES, 'UTF-8' ) . '</strong>'
				: '<span class="ve-muted">—</span>';
			$list .= '<tr><td>' . $kid . '</td><td><strong>'
				. htmlspecialchars( $human, ENT_QUOTES, 'UTF-8' ) . '</strong></td><td>'
				. $bitsCell . '</td><td><code>'
				. htmlspecialchars( $tech, ENT_QUOTES, 'UTF-8' ) . '</code></td><td>'
				. (int) ( $k['threshold'] ?? 0 ) . '/' . (int) ( $k['total_shares'] ?? 0 )
				. '</td><td class="ve-actions" style="margin:0">'
				. '<a href="/painel/chave/' . $kid . '">Ver / copiar</a> '
				. '<a class="secondary" href="/painel/chave/' . $kid . '.json">Exportar JSON</a> '
				. '<a class="secondary" href="/painel/chave/' . $kid . '#eliminar">Eliminar</a>'
				. '</td></tr>';
		}
		if ( ! $keys ) {
			$list .= '<tr><td colspan="6" class="ve-muted">Nenhuma chave ativa.</td></tr>';
		}
		$list .= '</tbody></table>';
		$list .= '<p class="ve-muted" style="margin-top:0.75rem">Para eliminar, abrir a chave e digitar exactamente '
			. '<code>' . $confirmEsc . '</code> (respeita maiúsculas/minúsculas; locale '
			. htmlspecialchars( $this->i18n->locale(), ENT_QUOTES, 'UTF-8' ) . ').</p>';
		return $list;
	}

	/**
	 * @return array{ok:bool,message:string,key_id?:int}
	 */
	private function executeDeleteKey( Request $req ): array {
		$keyId = (int) $req->input( 'key_id', '0' );
		$typed = $req->input( 'confirm_word', '' );
		$word  = $this->i18n->destructiveConfirmWord();

		if ( $keyId < 1 ) {
			return array(
				'ok'      => false,
				'message' => 'ID de chave inválido.',
			);
		}
		if ( ! $this->i18n->matchesDestructiveConfirm( $typed ) ) {
			return array(
				'ok'      => false,
				'key_id'  => $keyId,
				'message' => 'Eliminação cancelada. Digitar exactamente «' . $word . '» (case-sensitive) para confirmar.',
			);
		}
		$row = $this->findKeyRow( $keyId );
		if ( null === $row ) {
			return array(
				'ok'      => false,
				'key_id'  => $keyId,
				'message' => 'Chave não encontrada ou já eliminada.',
			);
		}
		$label = (string) ( $row['display_name'] ?? $row['key_label'] ?? ( 'Chave #' . $keyId ) );
		$ok    = $this->node->persistence->keys->trash( $keyId );
		if ( ! $ok ) {
			return array(
				'ok'      => false,
				'key_id'  => $keyId,
				'message' => 'Não foi possível eliminar a chave.',
			);
		}
		return array(
			'ok'      => true,
			'key_id'  => $keyId,
			'message' => 'Chave «' . $label . '» (#' . $keyId . ') eliminada (removida das chaves ativas).',
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function findKeyRow( int $keyId ): ?array {
		foreach ( $this->node->persistence->keys->listActive() as $k ) {
			if ( (int) ( $k['id'] ?? 0 ) === $keyId ) {
				return $k;
			}
		}
		return null;
	}

	private function buildPublicKeyPackageForKey( array $k ): array {
		$human = (string) ( $k['display_name'] ?? $k['key_label'] ?? 'chave' );
		$bits  = $this->resolveKeySizeBits( $k );
		return PublicKeyPackage::build(
			array(
				'key_label'   => $human,
				'key_size'    => $bits > 0 ? $bits : 512,
				'p'           => (string) ( $k['public_p'] ?? '' ),
				'q'           => (string) ( $k['public_q'] ?? '' ),
				'g'           => (string) ( $k['public_g'] ?? '' ),
				'y'           => (string) ( $k['public_y'] ?? '' ),
				'field_prime' => (string) ( $k['field_prime'] ?? '' ),
				'threshold_t' => (int) ( $k['threshold'] ?? 0 ),
				'total_n'     => (int) ( $k['total_shares'] ?? 0 ),
				'source_mode' => SiteModes::KEY_AUTHORITY,
				'cliente_id'  => $this->node->clienteId,
				'cliente_nome'=> $this->node->clienteId,
			)
		);
	}

	private function chavePublica( Request $req, int $keyId, bool $asJson ): Response {
		$this->node->requireMode( SiteModes::KEY_AUTHORITY );
		$flash = '';

		if ( ! $asJson && 'POST' === $req->method && 'delete_key' === $req->input( 'action' ) ) {
			$del = $this->executeDeleteKey(
				new Request(
					$req->method,
					$req->path,
					$req->get,
					array_merge(
						$req->post,
						array(
							'action'       => 'delete_key',
							'key_id'       => (string) $keyId,
							'confirm_word' => $req->input( 'confirm_word', '' ),
						)
					),
					$req->cookies,
					$req->server
				)
			);
			if ( ! empty( $del['ok'] ) ) {
				return Response::redirect( '/painel/keygen?msg=' . rawurlencode( (string) $del['message'] ) );
			}
			$flash = (string) ( $del['message'] ?? 'Eliminação falhou.' );
		}

		$row = $this->findKeyRow( $keyId );
		if ( null === $row ) {
			return Response::html(
				$this->shell->render( 'Chave', '<div class="ve-card"><h1>Chave não encontrada</h1><p class="ve-muted"><a href="/painel/keygen">Voltar às chaves</a></p></div>', $this->nav() ),
				404
			);
		}
		$pkg  = $this->buildPublicKeyPackageForKey( $row );
		$json = json_encode( $pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$json = is_string( $json ) ? $json . "\n" : "{}\n";
		if ( $asJson ) {
			$safe = preg_replace( '/[^a-zA-Z0-9_\-]+/', '-', (string) ( $row['display_name'] ?? $row['key_label'] ?? 'chave' ) ) ?: 'chave';
			return new Response(
				$json,
				200,
				array(
					'Content-Type'        => 'application/json; charset=UTF-8',
					'Content-Disposition' => 'attachment; filename="chave-publica-' . $keyId . '-' . $safe . '.json"',
				)
			);
		}
		$human = htmlspecialchars( (string) ( $row['display_name'] ?? $row['key_label'] ?? '' ), ENT_QUOTES, 'UTF-8' );
		$tech  = htmlspecialchars( (string) ( $row['key_label'] ?? '' ), ENT_QUOTES, 'UTF-8' );
		$bits  = $this->resolveKeySizeBits( $row );
		$bitsLabel = htmlspecialchars( $this->formatKeySizeLabel( $bits ), ENT_QUOTES, 'UTF-8' );
		$esc   = htmlspecialchars( $json, ENT_QUOTES, 'UTF-8' );
		$confirmWord = $this->i18n->destructiveConfirmWord();
		$confirmEsc  = htmlspecialchars( $confirmWord, ENT_QUOTES, 'UTF-8' );
		$body  = '<div class="ve-card"><h1>Chave pública</h1>'
			. ( $flash ? '<p class="ve-muted">' . htmlspecialchars( $flash, ENT_QUOTES, 'UTF-8' ) . '</p>' : '' )
			. '<p><strong>' . $human . '</strong></p>'
			. '<p class="ve-muted">Identificador técnico: <code>' . $tech . '</code> · ID #' . $keyId
			. ' · <strong class="ve-key-bits">' . $bitsLabel . '</strong>'
			. ' · limiar ' . (int) ( $row['threshold'] ?? 0 ) . '/' . (int) ( $row['total_shares'] ?? 0 ) . '</p>'
			. '<p class="ve-muted">Apenas componentes públicos (p, q, g, y). A chave privada não está armazenada neste nó após a geração.</p>'
			. '<label class="ve-field"><span>JSON (visualizar / copiar)</span>'
			. '<textarea id="ve-pubkey-json" readonly rows="16" style="max-width:100%;font-family:ui-monospace,monospace;font-size:0.85rem">'
			. $esc . '</textarea></label>'
			. '<div class="ve-actions">'
			. '<button type="button" id="ve-copy-pubkey">Copiar JSON</button>'
			. '<a class="secondary" href="/painel/chave/' . $keyId . '.json">Exportar JSON</a>'
			. '<a class="secondary" href="/painel/keygen">Voltar às chaves</a>'
			. '</div>'
			. '<p id="ve-copy-flash" class="ve-muted" hidden>JSON copiado.</p></div>'
			. '<div class="ve-card" id="eliminar"><h2>Eliminar chave</h2>'
			. '<p class="ve-muted">Acção destrutiva: a chave deixa de aparecer nas chaves ativas. Digitar exactamente '
			. '<code>' . $confirmEsc . '</code> (case-sensitive; locale '
			. htmlspecialchars( $this->i18n->locale(), ENT_QUOTES, 'UTF-8' ) . ').</p>'
			. '<form method="post" action="/painel/chave/' . $keyId . '">'
			. '<input type="hidden" name="action" value="delete_key" />'
			. '<input type="hidden" name="key_id" value="' . $keyId . '" />'
			. '<label class="ve-field"><span>Confirmação</span>'
			. '<input name="confirm_word" autocomplete="off" spellcheck="false" required placeholder="' . $confirmEsc . '" /></label>'
			. '<div class="ve-actions"><button type="submit">Eliminar chave</button></div>'
			. '</form></div>'
			. '<script>(function(){var b=document.getElementById("ve-copy-pubkey");var t=document.getElementById("ve-pubkey-json");var f=document.getElementById("ve-copy-flash");if(!b||!t)return;b.addEventListener("click",function(){t.select();t.setSelectionRange(0,t.value.length);var ok=false;if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t.value).then(function(){ok=true;if(f){f.hidden=false;f.textContent="JSON copiado.";}}).catch(function(){});}if(!ok){try{ok=document.execCommand("copy");}catch(e){}if(f){f.hidden=false;f.textContent=ok?"JSON copiado.":"Selecionar o texto e copiar manualmente.";}});})();</script>';
		return $this->page( 'Chave pública', $body );
	}

	/**
	 * @param array<string,mixed> $jobStatus
	 */
	private function keygenProgressMarkup( bool $visible, array $jobStatus = array() ): string {
		$pct = (int) ( $jobStatus['progress'] ?? 0 );
		$stage = htmlspecialchars( (string) ( $jobStatus['message'] ?? 'Preparar…' ), ENT_QUOTES, 'UTF-8' );
		$hidden = $visible ? '' : ' hidden';
		return <<<HTML
<div id="ve-keygen-progress" class="ve-keygen-progress"{$hidden}>
  <div class="ve-keygen-progress__head">
    <strong id="ve-keygen-pct">{$pct}%</strong>
    <span id="ve-keygen-stage">{$stage}</span>
  </div>
  <div class="ve-keygen-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$pct}" id="ve-keygen-bar-wrap">
    <div id="ve-keygen-bar" class="ve-keygen-progress__bar" style="width:{$pct}%"></div>
  </div>
  <p class="ve-muted" style="margin:0.6rem 0 0">Trabalho no servidor (worker). Pode sair e regressar — o estado persiste em <code>jobs.json</code>.</p>
</div>
<style>
.ve-keygen-progress{margin:1rem 0;padding:0.85rem 1rem;border:1px solid #c5d2dc;border-radius:10px;background:#f7fafc}
.ve-keygen-progress__head{display:flex;gap:0.75rem;align-items:baseline;margin-bottom:0.55rem;flex-wrap:wrap}
.ve-keygen-progress__head strong{font-size:1.15rem;color:#0b3d4a;min-width:3.5rem}
.ve-keygen-progress__track{height:0.85rem;background:#e2eaf0;border-radius:999px;overflow:hidden}
.ve-keygen-progress__bar{height:100%;width:0%;background:linear-gradient(90deg,#0b3d4a,#1a6b7c);transition:width 0.25s ease}
button.ve-btn-busy{opacity:0.75;cursor:wait}
</style>
HTML;
	}

	private function keygenProgressScript( bool $resumePoll ): string {
		$resume = $resumePoll ? 'true' : 'false';
		return <<<HTML
<script>
(function () {
  var form = document.getElementById('ve-keygen-form');
  var btn = document.getElementById('ve-keygen-submit');
  var cancelBtn = document.getElementById('ve-keygen-cancel');
  var box = document.getElementById('ve-keygen-progress');
  var bar = document.getElementById('ve-keygen-bar');
  var barWrap = document.getElementById('ve-keygen-bar-wrap');
  var pctEl = document.getElementById('ve-keygen-pct');
  var stageEl = document.getElementById('ve-keygen-stage');
  var flash = document.getElementById('ve-keygen-flash');
  var keys = document.getElementById('ve-keys-table');
  var labelIdle = btn ? btn.textContent : '';
  var timer = null;
  var resume = {$resume};

  function setProgress(pct, stage) {
    pct = Math.max(0, Math.min(100, pct|0));
    if (box) box.hidden = false;
    if (bar) bar.style.width = pct + '%';
    if (barWrap) barWrap.setAttribute('aria-valuenow', String(pct));
    if (pctEl) pctEl.textContent = pct + '%';
    if (stageEl && stage) stageEl.textContent = stage;
  }
  function setBusy(on) {
    if (!btn) return;
    btn.disabled = !!on;
    btn.classList.toggle('ve-btn-busy', !!on);
    btn.setAttribute('aria-busy', on ? 'true' : 'false');
    btn.textContent = on ? 'Geração em curso…' : labelIdle;
  }
  function setFlash(text, isError) {
    if (!flash) return;
    flash.hidden = !text;
    flash.textContent = text || '';
    flash.style.color = isError ? '#8a1f1f' : '';
  }
  function stopPoll() {
    if (timer) { clearInterval(timer); timer = null; }
  }
  function applyStatus(ev) {
    if (!ev) return;
    var pct = typeof ev.progress === 'number' ? ev.progress : (typeof ev.percent === 'number' ? ev.percent : 0);
    setProgress(pct, ev.message || ev.stage || '');
    if (ev.active) {
      setBusy(true);
      return;
    }
    stopPoll();
    setBusy(false);
    if (ev.stage === 'complete') {
      setFlash(ev.message || 'Concluído.', false);
      setProgress(100, ev.message || 'Concluído');
      if (ev.keys_html && keys) keys.innerHTML = ev.keys_html;
      setTimeout(function(){ location.reload(); }, 800);
    } else if (ev.stage === 'cancelled' || ev.stage === 'failed') {
      setFlash(ev.message || ev.error || 'Interrompido.', true);
    }
  }
  function pollOnce() {
    return fetch('/painel/keygen/status', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(applyStatus).catch(function(){});
  }
  function startPoll() {
    stopPoll();
    pollOnce();
    timer = setInterval(pollOnce, 1500);
  }

  if (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.fetch) return;
      ev.preventDefault();
      setBusy(true);
      setFlash('');
      setProgress(1, 'Iniciar job em background…');
      var fd = new FormData(form);
      fetch(form.action || '/painel/keygen', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function(r){ return r.json().then(function(j){ return { ok: r.ok, j: j }; }); })
      .then(function(res){
        applyStatus(res.j);
        setFlash(res.j.message || '', !res.j.ok && !res.j.active);
        if (res.j.active || res.j.ok) startPoll();
        else setBusy(false);
      }).catch(function(err){
        setFlash('Erro: ' + (err && err.message ? err.message : err), true);
        setBusy(false);
      });
    });
  }
  if (cancelBtn) {
    cancelBtn.addEventListener('click', function(){
      fetch('/painel/keygen/cancel', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function(r){ return r.json(); }).then(function(j){
        applyStatus(j);
        setTimeout(function(){ location.reload(); }, 500);
      });
    });
  }
  if (resume) startPoll();
})();
</script>
HTML;
	}

	private function parcelas( Request $req ): Response {
		$this->node->requireMode( SiteModes::TALLYING );
		$officialN = $this->node->users->countByRole( UserRegistryRoles::ROLE_OFFICIAL );
		$msg       = '';
		$imports   = $this->node->persistence->tallyImports->listSummaries();
		$importId  = (int) $req->input( 'import_id', $req->query( 'import_id', '0' ) );
		if ( $importId <= 0 && $imports ) {
			$importId = (int) ( $imports[0]['id'] ?? 0 );
		}

		if ( 'POST' === $req->method ) {
			if ( $officialN < 1 ) {
				$msg = 'Importar ou cadastrar autoridades eleitorais neste nó antes de submeter parcelas.';
			} elseif ( ! $this->sessionUserCanSubmitShare() ) {
				$msg = 'Entrar como autoridade eleitoral (ou administrador) para submeter uma parcela.';
			} else {
				$importId = max( 1, (int) $req->input( 'import_id', (string) $importId ) );
				$raw      = trim( $req->input( 'share_json' ) );
				$tmp      = (string) ( $req->files['share_file']['tmp_name'] ?? '' );
				if ( '' === $raw && is_readable( $tmp ) ) {
					$raw = (string) file_get_contents( $tmp );
				}
				$payload = json_decode( $raw, true );
				if ( ! is_array( $payload ) ) {
					$msg = 'JSON da parcela inválido.';
				} else {
					unset( $payload['assigned_login'], $payload['official_user_id'] );
					try {
						ShamirSecretSharing::validateSharePayload( $payload );
						$shareIndex = (int) $payload['share_index'];
						$threshold  = (int) $payload['threshold_t'];
						if ( $this->node->persistence->shareSubmissions->countByImportAndIndex( $importId, $shareIndex ) > 0 ) {
							$msg = "Índice de parcela {$shareIndex} já submetido para o import #{$importId}.";
						} else {
							$this->node->persistence->shareSubmissions->create(
								array(
									'tally_import_id'  => $importId,
									'key_id'           => (int) ( $payload['key_id'] ?? 0 ),
									'election_round_id'=> (int) ( $payload['election_round_id'] ?? 0 ),
									'official_user_id' => $this->session->currentUserId(),
									'share_index'      => $shareIndex,
									'share_payload'    => $payload,
									'threshold_t'      => $threshold,
									'total_n'          => (int) ( $payload['total_n'] ?? 0 ),
									'submitted_at'     => gmdate( 'c' ),
								)
							);
							$have = $this->node->persistence->shareSubmissions->countByImport( $importId );
							$msg  = "Parcela #{$shareIndex} submetida. Progresso: {$have}"
								. ( $threshold > 0 ? " / limiar {$threshold}" : '' ) . '.';
							if ( $threshold > 0 && $have >= $threshold ) {
								$msg .= ' Limiar Shamir atingido para este import.';
							}
						}
					} catch ( \Throwable $e ) {
						$msg = 'Parcela rejeitada: ' . $e->getMessage();
					}
				}
			}
		}

		$subs = $importId > 0
			? $this->node->persistence->shareSubmissions->listByImport( $importId )
			: array();
		$subRows = '';
		$thresholdSeen = 0;
		foreach ( $subs as $s ) {
			$thresholdSeen = max( $thresholdSeen, (int) ( $s['threshold_t'] ?? 0 ) );
			$uid = (int) ( $s['official_user_id'] ?? 0 );
			$u   = $uid > 0 ? $this->node->users->findById( $uid ) : null;
			$subRows .= '<tr><td>' . (int) ( $s['share_index'] ?? 0 ) . '</td><td>'
				. htmlspecialchars( (string) ( $u['login'] ?? ( '#' . $uid ) ), ENT_QUOTES, 'UTF-8' )
				. '</td><td>' . htmlspecialchars( (string) ( $s['submitted_at'] ?? '' ), ENT_QUOTES, 'UTF-8' ) . '</td></tr>';
		}
		if ( '' === $subRows ) {
			$subRows = '<tr><td colspan="3" class="ve-muted">Nenhuma parcela submetida ainda.</td></tr>';
		}
		$have = count( $subs );
		$progress = $thresholdSeen > 0
			? "{$have} / limiar {$thresholdSeen}" . ( $have >= $thresholdSeen ? ' — limiar atingido' : '' )
			: (string) $have;

		$importOpts = '';
		foreach ( $imports as $s ) {
			$id = (int) ( $s['id'] ?? 0 );
			$sel = $id === $importId ? ' selected' : '';
			$importOpts .= '<option value="' . $id . '"' . $sel . '>#' . $id . ' '
				. htmlspecialchars( (string) ( $s['status'] ?? '' ), ENT_QUOTES, 'UTF-8' ) . '</option>';
		}
		if ( '' === $importOpts ) {
			$importOpts = '<option value="1">#1 (criar import antes, se necessário)</option>';
		}

		$body = '<div class="ve-card"><h1>Parcelas Shamir</h1>'
			. '<p class="ve-muted">Cada autoridade sobe a sua parcela. Sem autoridades neste nó e sem submissões suficientes, o limiar Shamir não é atingido e a contagem não pode avançar.</p>'
			. ( $officialN < 1
				? '<p class="ve-muted"><a href="/painel/autoridades">Importar autoridades</a> primeiro.</p>'
				: '' )
			. ( $msg ? '<p class="ve-muted">' . htmlspecialchars( $msg, ENT_QUOTES, 'UTF-8' ) . '</p>' : '' )
			. '<form method="post" enctype="multipart/form-data" action="/painel/parcelas">'
			. '<label class="ve-field"><span>Import de apuração</span><select name="import_id">' . $importOpts . '</select></label>'
			. '<label class="ve-field"><span>JSON da parcela</span><textarea name="share_json" rows="8" placeholder="{ … share payload … }"></textarea></label>'
			. '<label class="ve-field"><span>Ou ficheiro parcela-*.json</span><input type="file" name="share_file" accept=".json,application/json" /></label>'
			. '<div class="ve-actions"><button type="submit">Submeter parcela</button>'
			. '<a class="secondary" href="/painel/autoridades">Autoridades</a></div></form></div>'
			. '<div class="ve-card"><h2>Submissões (import #' . (int) $importId . ')</h2>'
			. '<p class="ve-muted">Progresso: ' . htmlspecialchars( $progress, ENT_QUOTES, 'UTF-8' ) . '</p>'
			. '<table class="ve-table"><thead><tr><th>Índice</th><th>Autoridade</th><th>Quando</th></tr></thead><tbody>'
			. $subRows . '</tbody></table></div>';
		return $this->page( 'Parcelas', $body );
	}

	private function sessionUserCanSubmitShare(): bool {
		$user = $this->node->users->findById( $this->session->currentUserId() );
		if ( null === $user ) {
			return false;
		}
		$roles = array_map( 'strval', (array) ( $user['roles'] ?? array() ) );
		return in_array( UserRegistryRoles::ROLE_OFFICIAL, $roles, true )
			|| in_array( UserRegistryRoles::ROLE_ADMIN, $roles, true );
	}

	private function courier( Request $req ): Response {
		$courierDir = dirname( $this->node->dataDir ) . '/courier';
		if ( ! is_dir( $courierDir ) ) {
			mkdir( $courierDir, 0700, true );
		}
		$msg = '';
		if ( 'POST' === $req->method && isset( $req->files['material'] ) ) {
			$file = $req->files['material'];
			$tmp  = (string) ( $file['tmp_name'] ?? '' );
			$name = basename( (string) ( $file['name'] ?? 'upload.json' ) );
			$name = preg_replace( '/[^a-zA-Z0-9._\-]/', '', $name ) ?: 'upload.json';
			if ( is_readable( $tmp ) ) {
				copy( $tmp, $courierDir . '/' . $name );
				$msg = 'Material guardado: ' . $name;
			}
		}
		$files = glob( $courierDir . '/*' ) ?: array();
		$list  = '<ul>';
		foreach ( $files as $f ) {
			$bn = basename( $f );
			$list .= '<li><code>' . htmlspecialchars( $bn, ENT_QUOTES, 'UTF-8' ) . '</code> (' . filesize( $f ) . ' B)</li>';
		}
		$list .= '</ul>';
		$body = '<div class="ve-card"><h1>Courier manual</h1>'
			. '<p class="ve-muted">Pasta partilhada entre nós (sem sync automático): <code>' . htmlspecialchars( $courierDir, ENT_QUOTES, 'UTF-8' ) . '</code></p>'
			. ( $msg ? '<p class="ve-muted">' . htmlspecialchars( $msg, ENT_QUOTES, 'UTF-8' ) . '</p>' : '' )
			. '<form method="post" enctype="multipart/form-data"><label class="ve-field"><span>Enviar JSON</span><input type="file" name="material" required /></label>'
			. '<div class="ve-actions"><button type="submit">Carregar</button></div></form></div>'
			. '<div class="ve-card"><h2>Ficheiros</h2>' . $list . '</div>';
		return $this->page( 'Courier', $body );
	}

	private function eleicoes(): Response {
		$this->node->requireMode( SiteModes::VOTING );
		$elections = $this->node->persistence->elections->listElections();
		$rows = '';
		foreach ( $elections as $e ) {
			$rows .= '<tr><td>' . (int) $e['id'] . '</td><td>' . htmlspecialchars( (string) ( $e['title'] ?? '' ), ENT_QUOTES, 'UTF-8' ) . '</td></tr>';
		}
		if ( '' === $rows ) {
			$rows = '<tr><td colspan="2" class="ve-muted">Nenhuma eleição neste nó. Importar chave pública via courier e criar no fluxo de votação/piloto.</td></tr>';
		}
		$body = '<div class="ve-card"><h1>Eleições</h1><table class="ve-table"><thead><tr><th>ID</th><th>Título</th></tr></thead><tbody>'
			. $rows . '</tbody></table></div>';
		return $this->page( 'Eleições', $body );
	}

	private function tallyImport( Request $req ): Response {
		$this->node->requireMode( SiteModes::TALLYING );
		$msg = '';
		$courierDir = dirname( $this->node->dataDir ) . '/courier';
		if ( 'POST' === $req->method ) {
			$voteFile = $courierDir . '/vote-material.json';
			if ( is_readable( $voteFile ) ) {
				$raw = json_decode( (string) file_get_contents( $voteFile ), true );
				if ( is_array( $raw ) ) {
					$id = $this->node->persistence->tallyImports->create(
						array(
							'source'     => 'courier',
							'status'     => 'imported',
							'created_at' => gmdate( 'c' ),
							'payload'    => $raw,
						)
					);
					$msg = "Importação #{$id} criada a partir de vote-material.json.";
				} else {
					$msg = 'vote-material.json inválido.';
				}
			} else {
				$msg = 'Falta vote-material.json no courier.';
			}
		}
		$summaries = $this->node->persistence->tallyImports->listSummaries();
		$list = '<table class="ve-table"><thead><tr><th>ID</th><th>Estado</th></tr></thead><tbody>';
		foreach ( $summaries as $s ) {
			$list .= '<tr><td>' . (int) ( $s['id'] ?? 0 ) . '</td><td>' . htmlspecialchars( (string) ( $s['status'] ?? '' ), ENT_QUOTES, 'UTF-8' ) . '</td></tr>';
		}
		$list .= '</tbody></table>';
		$body = '<div class="ve-card"><h1>Importação da apuração</h1>'
			. ( $msg ? '<p class="ve-muted">' . htmlspecialchars( $msg, ENT_QUOTES, 'UTF-8' ) . '</p>' : '' )
			. '<form method="post"><div class="ve-actions"><button type="submit">Importar vote-material.json do courier</button></div></form></div>'
			. '<div class="ve-card"><h2>Imports</h2>' . $list . '</div>';
		return $this->page( 'Importar', $body );
	}

	private function certificar( Request $req ): Response {
		$this->node->requireMode( SiteModes::TALLYING );
		$msg = '';
		if ( 'POST' === $req->method ) {
			$id = $this->node->persistence->certifications->create(
				array(
					'import_id'  => (int) $req->input( 'import_id', '0' ),
					'status'     => 'draft',
					'created_at' => gmdate( 'c' ),
					'note'       => 'Certificação HTTP mínima — completar reconstrução via piloto/courier.',
				)
			);
			$msg = "Certificação #{$id} registada (rascunho).";
		}
		$body = '<div class="ve-card"><h1>Certificação</h1>'
			. ( $msg ? '<p class="ve-muted">' . htmlspecialchars( $msg, ENT_QUOTES, 'UTF-8' ) . '</p>' : '' )
			. '<p class="ve-muted">Só faz sentido após o limiar Shamir em <a href="/painel/parcelas">/painel/parcelas</a> (autoridades a submeter parcelas).</p>'
			. '<form method="post"><label class="ve-field"><span>Import ID</span><input name="import_id" value="1" /></label>'
			. '<div class="ve-actions"><button type="submit">Criar certificação</button></div></form>'
			. '<p class="ve-muted">Para apuração criptográfica completa use o piloto E3 (`ve-node pilot`) com as parcelas no courier; esta UI regista o artefacto no nó.</p></div>';
		return $this->page( 'Certificação', $body );
	}

	private function journey( Request $req, string $step ): Response {
		$this->node->requireMode( SiteModes::VOTING );
		if ( ! $this->session->isAuthenticated() ) {
			return Response::redirect( '/login?next=' . rawurlencode( $req->path ) );
		}

		if ( JourneySteps::BOOTH === $step && 'POST' === $req->method ) {
			return $this->castVote( $req );
		}

		$title = match ( $step ) {
			JourneySteps::WELCOME => 'Boas-vindas',
			JourneySteps::BOOTH => 'Cabina',
			JourneySteps::THANK_YOU => 'Obrigado',
			default => 'Voto',
		};

		$inner = match ( $step ) {
			JourneySteps::WELCOME => '<div class="ve-card"><h1>Boas-vindas</h1><p class="ve-muted">Jornada do eleitor neste nó de votação.</p>'
				. '<div class="ve-actions"><a href="/voto/cabina">Ir à cabina</a></div></div>',
			JourneySteps::BOOTH => '<div class="ve-card"><h1>Cabina de votação</h1>'
				. '<p class="ve-muted">Voto homomórfico mínimo (contagem 0/1) usando a chave pública importada no nó.</p>'
				. '<form method="post" action="/voto/cabina"><label class="ve-field"><span>Escolha</span>'
				. '<select name="choice"><option value="1">Sim / opção A</option><option value="0">Não / opção B</option></select></label>'
				. '<div class="ve-actions"><button type="submit">Confirmar voto</button></div></form></div>',
			JourneySteps::THANK_YOU => '<div class="ve-card"><h1>Voto registado</h1><p class="ve-muted">Obrigado. Recibo em baixo (se aplicável).</p>'
				. '<p><code>' . htmlspecialchars( $req->query( 'receipt', '' ), ENT_QUOTES, 'UTF-8' ) . '</code></p>'
				. '<div class="ve-actions"><a href="/voto">Voltar</a></div></div>',
			default => '<div class="ve-card"><p>Passo desconhecido.</p></div>',
		};

		return $this->page( $title, $inner );
	}

	private function castVote( Request $req ): Response {
		$choice = (int) $req->input( 'choice', '1' ) > 0 ? 1 : 0;
		$keys   = $this->node->persistence->keys->listActive();
		if ( ! $keys ) {
			// Try import public key from courier.
			$pkFile = dirname( $this->node->dataDir ) . '/courier/public-key.json';
			if ( is_readable( $pkFile ) ) {
				$pkg = json_decode( (string) file_get_contents( $pkFile ), true );
				if ( is_array( $pkg ) ) {
					$pub = $pkg['public_key'] ?? $pkg;
					$this->node->persistence->keys->create(
						array(
							'key_label' => 'imported-courier',
							'key_size'  => (int) ( $pkg['key_size'] ?? 0 ),
							'public_p'  => (string) ( $pub['p'] ?? '' ),
							'public_q'  => (string) ( $pub['q'] ?? '' ),
							'public_g'  => (string) ( $pub['g'] ?? '' ),
							'public_y'  => (string) ( $pub['y'] ?? '' ),
						)
					);
					$keys = $this->node->persistence->keys->listActive();
				}
			}
		}
		if ( ! $keys ) {
			$this->flash = 'Sem chave pública neste nó. Colocar public-key.json no courier.';
			return Response::redirect( '/voto/cabina' );
		}
		$k = $keys[0];
		$p = \RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\BigInt::fromDecimalString( (string) $k['public_p'] );
		$q = \RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\BigInt::fromDecimalString( (string) $k['public_q'] );
		$g = \RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\BigInt::fromDecimalString( (string) $k['public_g'] );
		$y = \RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\BigInt::fromDecimalString( (string) $k['public_y'] );
		$ct = HomomorphicTally::encryptCount( $choice, $p, $q, $g, $y );
		$uid = $this->session->currentUserId();
		$voteId = $this->node->persistence->votes->store(
			array(
				'election_id' => 0,
				'round_id'    => 0,
				'voter_id'    => $uid,
				'user_id'     => $uid,
				'question_id' => 1,
				'ciphertext'  => array(
					'c1' => \RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\BigInt::toDecimalString( $ct->getC1() ),
					'c2' => \RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\BigInt::toDecimalString( $ct->getC2() ),
				),
				'choice'      => $choice,
				'cast_at'     => gmdate( 'c' ),
			)
		);
		$receipt = hash( 'sha256', $voteId . '|' . $uid . '|' . $choice . '|' . gmdate( 'c' ) );
		return Response::redirect( '/voto/obrigado?receipt=' . rawurlencode( $receipt ) );
	}

	private function serveAsset( string $path ): Response {
		$rel  = substr( $path, strlen( '/assets/' ) );
		$rel  = str_replace( array( '..', "\0" ), '', $rel );
		$file = $this->packageRoot . '/assets/' . $rel;
		if ( ! is_readable( $file ) || ! is_file( $file ) ) {
			return Response::text( 'Not found', 404 );
		}
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$mime = match ( $ext ) {
			'css' => 'text/css; charset=UTF-8',
			'js' => 'application/javascript; charset=UTF-8',
			'svg' => 'image/svg+xml',
			'png' => 'image/png',
			'jpg', 'jpeg' => 'image/jpeg',
			'woff2' => 'font/woff2',
			default => 'application/octet-stream',
		};
		return new Response( (string) file_get_contents( $file ), 200, array( 'Content-Type' => $mime ) );
	}
}
