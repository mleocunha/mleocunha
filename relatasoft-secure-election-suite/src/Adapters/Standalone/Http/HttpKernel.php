<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http;

use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Identity\FileJsonUserStore;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\NodeRuntime;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneySteps;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\UserRegistryRoles;
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
		$locale = CatalogI18n::fromAcceptLanguage( $acceptLanguage, 'pt_BR' );
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

		return match ( true ) {
			'/painel' === $path => $this->painelHome(),
			'/painel/cadastro' === $path => $this->cadastro( $req ),
			'/painel/autoridades' === $path => $this->autoridades( $req ),
			'/painel/keygen' === $path => $this->keygen( $req ),
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
			array( 'href' => '/painel', 'label' => $this->i18n->t( 'Settings' ) === 'Settings' ? 'Painel' : 'Painel' ),
		);
		if ( SiteModes::VOTING === $mode ) {
			$items[] = array( 'href' => '/painel/cadastro', 'label' => 'Cadastro' );
			$items[] = array( 'href' => '/painel/eleicoes', 'label' => $this->i18n->t( 'Elections' ) );
			$items[] = array( 'href' => '/voto', 'label' => 'Voto' );
			$items[] = array( 'href' => '/painel/courier', 'label' => 'Courier' );
		}
		if ( SiteModes::KEY_AUTHORITY === $mode ) {
			$items[] = array( 'href' => '/painel/autoridades', 'label' => 'Autoridades' );
			$items[] = array( 'href' => '/painel/keygen', 'label' => $this->i18n->t( 'Key Authority' ) );
			$items[] = array( 'href' => '/painel/courier', 'label' => 'Courier' );
		}
		if ( SiteModes::TALLYING === $mode ) {
			$items[] = array( 'href' => '/painel/importar', 'label' => $this->i18n->t( 'Tally Import' ) );
			$items[] = array( 'href' => '/painel/certificar', 'label' => $this->i18n->t( 'Certification' ) );
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
		return new Response(
			'',
			302,
			array(
				'Location'   => '/login',
				'Set-Cookie' => CookieSessionPort::COOKIE . '=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax',
			)
		);
	}

	private function painelHome(): Response {
		$mode = $this->node->mode->getMode();
		$cards = '';
		if ( SiteModes::VOTING === $mode ) {
			$cards .= $this->card( 'Cadastro Eleitoral', 'Importar .rsv e listar papéis.', '/painel/cadastro' );
			$cards .= $this->card( 'Eleições', 'Ver eleições neste nó.', '/painel/eleicoes' );
			$cards .= $this->card( 'Jornada /voto', 'Boas-vindas, cabina e obrigado.', '/voto' );
			$cards .= $this->card( 'Courier', 'Importar chave pública / exportar material de voto.', '/painel/courier' );
		} elseif ( SiteModes::KEY_AUTHORITY === $mode ) {
			$cards .= $this->card( 'Autoridades eleitorais', 'Cadastrar autoridades antes de atribuir parcelas Shamir.', '/painel/autoridades' );
			$cards .= $this->card( $this->i18n->t( 'Key Authority' ), 'Gerar chave e atribuir parcelas às autoridades.', '/painel/keygen' );
			$cards .= $this->card( 'Courier', 'Exportar chave pública e parcelas.', '/painel/courier' );
		} else {
			$cards .= $this->card( $this->i18n->t( 'Tally Import' ), 'Importar material de voto + parcelas.', '/painel/importar' );
			$cards .= $this->card( $this->i18n->t( 'Certification' ), 'Apurar e certificar.', '/painel/certificar' );
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
		$this->node->requireMode( SiteModes::KEY_AUTHORITY );
		$msg = '';
		$users = $this->node->users;
		if ( ! $users instanceof FileJsonUserStore ) {
			throw new \RuntimeException( 'Autoridades require FileJsonUserStore.' );
		}

		if ( 'POST' === $req->method ) {
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

		$list = $users->listByRole( UserRegistryRoles::ROLE_OFFICIAL );
		$rows = '';
		foreach ( $list as $u ) {
			$rows .= '<tr><td>' . (int) $u['id'] . '</td><td>'
				. htmlspecialchars( (string) $u['displayName'], ENT_QUOTES, 'UTF-8' ) . '</td><td><code>'
				. htmlspecialchars( (string) $u['login'], ENT_QUOTES, 'UTF-8' ) . '</code></td><td>'
				. htmlspecialchars( (string) $u['email'], ENT_QUOTES, 'UTF-8' ) . '</td></tr>';
		}
		if ( '' === $rows ) {
			$rows = '<tr><td colspan="4" class="ve-muted">Nenhuma autoridade cadastrada. Este passo é obrigatório antes de gerar e atribuir parcelas Shamir.</td></tr>';
		}

		$body = '<div class="ve-card"><h1>Autoridades eleitorais</h1>'
			. '<p class="ve-muted">Cadastrar as contas que receberão as parcelas Shamir. Sem autoridades suficientes, a geração de chave não avança.</p>'
			. ( $msg ? '<p class="ve-muted">' . htmlspecialchars( $msg, ENT_QUOTES, 'UTF-8' ) . '</p>' : '' )
			. '<form method="post" action="/painel/autoridades">'
			. '<label class="ve-field"><span>Login</span><input name="login" required autocomplete="off" /></label>'
			. '<label class="ve-field"><span>Nome</span><input name="displayName" /></label>'
			. '<label class="ve-field"><span>E-mail</span><input type="email" name="email" required /></label>'
			. '<label class="ve-field"><span>Senha</span><input type="password" name="password" required autocomplete="new-password" /></label>'
			. '<div class="ve-actions"><button type="submit">Cadastrar autoridade</button>'
			. '<a class="secondary" href="/painel/keygen">Ir à geração de chave</a></div></form></div>'
			. '<div class="ve-card"><h2>Cadastradas (' . count( $list ) . ')</h2>'
			. '<table class="ve-table"><thead><tr><th>ID</th><th>Nome</th><th>Login</th><th>E-mail</th></tr></thead><tbody>'
			. $rows . '</tbody></table></div>';
		return $this->page( 'Autoridades', $body );
	}

	private function keygen( Request $req ): Response {
		$this->node->requireMode( SiteModes::KEY_AUTHORITY );
		$msg       = '';
		$officials = $this->node->users->listByRole( UserRegistryRoles::ROLE_OFFICIAL );
		$officialN = count( $officials );

		if ( 'POST' === $req->method ) {
			$bits = max( 256, min( 1024, (int) $req->input( 'bits', '512' ) ) );
			$th   = max( 2, (int) $req->input( 'threshold', '2' ) );
			$n    = max( $th, (int) $req->input( 'shares', (string) max( 3, $officialN ) ) );
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
				$msg = "É necessário cadastrar pelo menos {$n} autoridades eleitorais antes de gerar {$n} parcelas. Há {$officialN} cadastrada(s).";
			} elseif ( count( $selected ) !== $n ) {
				$msg = "Seleccionar exactamente {$n} autoridades para receber as parcelas (seleccionadas: " . count( $selected ) . ').';
			} else {
				$byId = array();
				foreach ( $officials as $o ) {
					$byId[ (int) $o['id'] ] = $o;
				}
				$okIds = array();
				foreach ( $selected as $uid ) {
					if ( ! isset( $byId[ $uid ] ) ) {
						$msg = 'Uma das autoridades seleccionadas não é válida.';
						break;
					}
					$okIds[] = $uid;
				}
				if ( '' === $msg ) {
					$kp    = ElGamal::generateKeyPair( $bits );
					$x     = $kp->getPrivateGmp();
					$field = PrimeGenerator::generatePrimeGreaterThan( $x, 64 );
					$shares = ShamirSecretSharing::splitSecret( $x, $th, $n, $field );
					$label = 'http-' . gmdate( 'Ymd-His' );
					$keyId = $this->node->persistence->keys->create(
						array(
							'key_label'    => $label,
							'public_p'     => $kp->getP(),
							'public_q'     => $kp->getQ(),
							'public_g'     => $kp->getG(),
							'public_y'     => $kp->getY(),
							'threshold'    => $th,
							'total_shares' => $n,
							'field_prime'  => BigInt::toDecimalString( $field ),
						)
					);
					$pub = array(
						'p' => $kp->getP(),
						'q' => $kp->getQ(),
						'g' => $kp->getG(),
						'y' => $kp->getY(),
					);
					$courierDir = dirname( $this->node->dataDir ) . '/courier';
					$courier    = new MaterialCourier( $courierDir );
					foreach ( $shares as $i => $point ) {
						$idx     = (int) ( $point['x'] ?? ( $i + 1 ) );
						$uid     = $okIds[ $i ];
						$payload = ShamirSecretSharing::buildSharePayload(
							$keyId,
							0,
							$th,
							$n,
							$field,
							$idx,
							$point['y'],
							$pub
						);
						$this->node->persistence->shares->create(
							array(
								'key_id'           => $keyId,
								'official_user_id' => $uid,
								'share_index'      => $idx,
								'share_payload'    => $payload,
								'threshold_t'      => $th,
								'total_n'          => $n,
								'field_prime'      => BigInt::toDecimalString( $field ),
								'status'           => 'assigned',
							)
						);
						$who = (string) ( $byId[ $uid ]['login'] ?? (string) $uid );
						$courier->writeJson(
							'parcela-' . $idx . '.json',
							array_merge(
								$payload,
								array(
									'assigned_login' => $who,
									'official_user_id' => $uid,
								)
							)
						);
					}
					$pkg = PublicKeyPackage::build(
						array(
							'key_label'   => $label,
							'key_size'    => $bits,
							'p'           => $kp->getP(),
							'q'           => $kp->getQ(),
							'g'           => $kp->getG(),
							'y'           => $kp->getY(),
							'field_prime' => BigInt::toDecimalString( $field ),
							'threshold_t' => $th,
							'total_n'     => $n,
							'source_mode' => SiteModes::KEY_AUTHORITY,
							'cliente_id'  => $this->node->clienteId,
							'cliente_nome'=> $this->node->clienteId,
						)
					);
					$courier->writeJson( 'public-key.json', $pkg );
					$msg = "Chave #{$keyId} gerada; {$n} parcelas atribuídas às autoridades seleccionadas; courier actualizado.";
				}
			}
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

		$keys = $this->node->persistence->keys->listActive();
		$list = '<table class="ve-table"><thead><tr><th>ID</th><th>Label</th><th>t/n</th><th>Atribuições</th></tr></thead><tbody>';
		foreach ( $keys as $k ) {
			$kid   = (int) $k['id'];
			$asg   = $this->node->persistence->shares->listByKey( $kid );
			$names = array();
			foreach ( $asg as $s ) {
				$uid = (int) ( $s['official_user_id'] ?? 0 );
				$u   = $uid > 0 ? $this->node->users->findById( $uid ) : null;
				$names[] = $u
					? ( (string) $u['login'] . ' #' . (int) ( $s['share_index'] ?? 0 ) )
					: ( '#' . $uid );
			}
			$list .= '<tr><td>' . $kid . '</td><td>' . htmlspecialchars( (string) ( $k['key_label'] ?? '' ), ENT_QUOTES, 'UTF-8' )
				. '</td><td>' . (int) ( $k['threshold'] ?? 0 ) . '/' . (int) ( $k['total_shares'] ?? 0 )
				. '</td><td>' . htmlspecialchars( $names ? implode( ', ', $names ) : '—', ENT_QUOTES, 'UTF-8' ) . '</td></tr>';
		}
		$list .= '</tbody></table>';

		$canGenerate = $officialN >= 2;
		$body = '<div class="ve-card"><h1>Autoridade de chaves</h1>'
			. '<p class="ve-muted">O cadastramento de autoridades eleitorais é obrigatório antes da atribuição de parcelas Shamir.</p>'
			. ( $msg ? '<p class="ve-muted">' . htmlspecialchars( $msg, ENT_QUOTES, 'UTF-8' ) . '</p>' : '' )
			. ( $canGenerate
				? '<form method="post">'
					. '<label class="ve-field"><span>Bits</span><input name="bits" value="512" /></label>'
					. '<label class="ve-field"><span>Limiar (t)</span><input name="threshold" value="2" /></label>'
					. '<label class="ve-field"><span>Parcelas (n)</span><input name="shares" value="' . max( 3, $officialN ) . '" /></label>'
					. '<h2>Autoridades que recebem parcelas</h2>'
					. '<p class="ve-muted">Seleccionar exactamente n contas (papel autoridade / editor).</p>'
					. $checkboxes
					. '<div class="ve-actions"><button type="submit">Gerar chave + atribuir parcelas</button></div></form>'
				: '<p class="ve-muted">Cadastrar pelo menos duas autoridades em '
					. '<a href="/painel/autoridades">/painel/autoridades</a> antes de gerar a chave.</p>' )
			. '</div>'
			. '<div class="ve-card"><h2>Chaves activas</h2>' . $list . '</div>';
		return $this->page( 'Keygen', $body );
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
