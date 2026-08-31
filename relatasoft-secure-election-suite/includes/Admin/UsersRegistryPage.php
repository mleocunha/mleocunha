<?php
/**
 * Cadastro de usuários do Painel — escopo por modo de operação.
 *
 * A página dedicada redireciona para Cadastro Eleitoral; a UI de listagem/criação
 * é reutilizada via {@see self::rses_render_sections()}.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\RegistryListPager;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Lista e cria contas adequadas a cada modo (chaves / votação / apuração).
 *
 * Cada papel tem paginação própria (25/50/100/200; default 25) para não
 * sobrecarregar o browser nem o servidor com o cadastro completo.
 */
class UsersRegistryPage {

	public const SLUG = 'rses-users-registry';

	public static function register(): void {
		add_action( 'admin_post_rses_create_registry_user', array( self::class, 'handle_create' ) );
	}

	public static function render(): void {
		// Alias legado: unifica em Cadastro Eleitoral.
		wp_safe_redirect( admin_url( 'admin.php?page=rses-electoral-roll' ) );
		exit;
	}

	/**
	 * Render list+create sections (embedded in Cadastro Eleitoral).
	 */
	public static function rses_render_sections(): void {
		if ( ! ModeLock::rses_has_mode() ) {
			echo '<div class="rses-panel rses-panel-info"><p>' . esc_html__( 'Definir o modo de operação antes de gerenciar o cadastro eleitoral.', 'relatasoft-secure-election-suite' ) . '</p></div>';
			return;
		}

		$mode   = ModeLock::rses_get_mode();
		$roles  = UserRegistryService::roles_for_mode( $mode );
		$state  = UserRegistryService::pager_state_from_request( $roles, $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = UserRegistryService::grouped_users_paged( $mode, $state );
		$labels = UserRegistryService::role_labels();
		$create = UserRegistryService::creatable_roles( $mode );
		$notice = isset( $_GET['ve_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ve_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$mode_blurb = match ( $mode ) {
			ModeLock::RSES_MODE_KEY_AUTHORITY => __( 'Neste modo (Autoridade-chave) constam Administrador Eleitoral, Autoridades Eleitorais, Auditor e Gestor pelo Cliente.', 'relatasoft-secure-election-suite' ),
			ModeLock::RSES_MODE_VOTING => __( 'Neste modo (Plataforma de votação) constam Administrador Eleitoral, Autoridades Eleitorais, Auditor, Gestor pelo Cliente e Eleitores.', 'relatasoft-secure-election-suite' ),
			ModeLock::RSES_MODE_TALLYING => __( 'Neste modo (Apuração / certificação) constam Administrador Eleitoral, Autoridades Eleitorais, Auditor e Gestor pelo Cliente.', 'relatasoft-secure-election-suite' ),
			default => __( 'Cadastro eleitoral do Painel.', 'relatasoft-secure-election-suite' ),
		};
		?>
		<?php if ( $notice ) : ?>
			<div class="rses-panel rses-panel-info"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<p class="rses-panel-desc"><?php echo esc_html( $mode_blurb ); ?></p>

		<section class="rses-panel rses-panel-card">
			<header class="rses-panel-header">
				<p class="rses-panel-kicker"><?php esc_html_e( 'Novo acesso', 'relatasoft-secure-election-suite' ); ?></p>
				<h2 class="rses-panel-title"><?php esc_html_e( 'Cadastrar usuário', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="rses-panel-desc"><?php esc_html_e( 'Criar uma conta do sítio com o papel adequado a este modo.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
				<input type="hidden" name="action" value="rses_create_registry_user" />
				<?php Nonce::rses_field( 'rses_create_registry_user' ); ?>
				<div class="rses-field-grid">
					<div class="rses-field">
						<label class="rses-field-label" for="rses_reg_login"><?php esc_html_e( 'Login', 'relatasoft-secure-election-suite' ); ?></label>
						<input type="text" name="user_login" id="rses_reg_login" class="regular-text" required autocomplete="off" />
					</div>
					<div class="rses-field">
						<label class="rses-field-label" for="rses_reg_email"><?php esc_html_e( 'E-mail', 'relatasoft-secure-election-suite' ); ?></label>
						<input type="email" name="user_email" id="rses_reg_email" class="regular-text" required autocomplete="off" />
					</div>
					<div class="rses-field">
						<label class="rses-field-label" for="rses_reg_name"><?php esc_html_e( 'Nome de exibição', 'relatasoft-secure-election-suite' ); ?></label>
						<input type="text" name="display_name" id="rses_reg_name" class="regular-text" />
					</div>
					<div class="rses-field">
						<label class="rses-field-label" for="rses_reg_pass"><?php esc_html_e( 'Senha', 'relatasoft-secure-election-suite' ); ?></label>
						<input type="password" name="user_pass" id="rses_reg_pass" class="regular-text" required autocomplete="new-password" minlength="8" />
					</div>
					<div class="rses-field">
						<label class="rses-field-label" for="rses_reg_role"><?php esc_html_e( 'Papel', 'relatasoft-secure-election-suite' ); ?></label>
						<select name="user_role" id="rses_reg_role" required>
							<?php foreach ( $create as $role ) : ?>
								<option value="<?php echo esc_attr( $role ); ?>"><?php echo esc_html( $labels[ $role ] ?? $role ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<p class="rses-form-actions">
					<button type="submit" class="button button-primary rses-btn-primary"><?php esc_html_e( 'Cadastrar', 'relatasoft-secure-election-suite' ); ?></button>
				</p>
			</form>
		</section>

		<?php foreach ( $paged as $role => $block ) : ?>
			<?php
			$users       = $block['users'];
			$total       = (int) $block['total'];
			$page        = (int) $block['page'];
			$per_page    = (int) $block['per_page'];
			$total_pages = (int) $block['total_pages'];
			?>
			<section class="rses-panel rses-panel-card" id="rses-roll-<?php echo esc_attr( $role ); ?>">
				<header class="rses-panel-header">
					<p class="rses-panel-kicker"><?php esc_html_e( 'Papel', 'relatasoft-secure-election-suite' ); ?></p>
					<h2 class="rses-panel-title">
						<?php echo esc_html( $labels[ $role ] ?? $role ); ?>
						<small>(<?php echo esc_html( (string) $total ); ?>)</small>
					</h2>
				</header>
				<?php if ( 0 === $total ) : ?>
					<p><?php esc_html_e( 'Nenhuma conta com este papel.', 'relatasoft-secure-election-suite' ); ?></p>
				<?php else : ?>
					<div class="rses-table-wrap">
						<table class="rses-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Nome', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Login', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'E-mail', 'relatasoft-secure-election-suite' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $users as $user ) : ?>
									<tr>
										<td><?php echo esc_html( (string) ( $user['displayName'] ?? '' ) ); ?></td>
										<td><code><?php echo esc_html( (string) ( $user['login'] ?? '' ) ); ?></code></td>
										<td><?php echo esc_html( (string) ( $user['email'] ?? '' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php self::rses_render_pager( $role, $page, $per_page, $total_pages, $total, $paged ); ?>
				<?php endif; ?>
			</section>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Controlos de paginação: densidade, primeira/anterior/próxima/última e salto directo.
	 *
	 * @param array<string,array{page:int,per_page:int,total:int,total_pages:int,users:list<array<string,mixed>>}> $all_paged
	 */
	private static function rses_render_pager(
		string $role,
		int $page,
		int $per_page,
		int $total_pages,
		int $total,
		array $all_paged
	): void {
		$base = array( 'page' => 'rses-electoral-roll' );
		// Preservar o estado de paginação dos outros papéis.
		foreach ( $all_paged as $other_role => $other ) {
			if ( $other_role === $role ) {
				continue;
			}
			$base[ RegistryListPager::pageQueryKey( $other_role ) ]    = (string) (int) $other['page'];
			$base[ RegistryListPager::perPageQueryKey( $other_role ) ] = (string) (int) $other['per_page'];
		}

		$page_key = RegistryListPager::pageQueryKey( $role );
		$pp_key   = RegistryListPager::perPageQueryKey( $role );

		$url_for = static function ( int $target_page, int $target_pp ) use ( $base, $page_key, $pp_key, $role ): string {
			$args              = $base;
			$args[ $page_key ] = (string) $target_page;
			$args[ $pp_key ]   = (string) $target_pp;
			return admin_url( 'admin.php?' . http_build_query( $args ) ) . '#rses-roll-' . rawurlencode( $role );
		};

		$first_url = $url_for( 1, $per_page );
		$prev_url  = $url_for( max( 1, $page - 1 ), $per_page );
		$next_url  = $url_for( min( $total_pages, $page + 1 ), $per_page );
		$last_url  = $url_for( $total_pages, $per_page );

		$from = $total > 0 ? ( ( $page - 1 ) * $per_page ) + 1 : 0;
		$to   = min( $total, $page * $per_page );
		?>
		<nav class="rses-pager" aria-label="<?php echo esc_attr( sprintf( /* translators: role label context */ __( 'Paginação do cadastro', 'relatasoft-secure-election-suite' ) ) ); ?>">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="rses-pager-form">
				<input type="hidden" name="page" value="rses-electoral-roll" />
				<?php foreach ( $all_paged as $other_role => $other ) : ?>
					<?php if ( $other_role === $role ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<input type="hidden" name="<?php echo esc_attr( RegistryListPager::pageQueryKey( $other_role ) ); ?>" value="<?php echo esc_attr( (string) (int) $other['page'] ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( RegistryListPager::perPageQueryKey( $other_role ) ); ?>" value="<?php echo esc_attr( (string) (int) $other['per_page'] ); ?>" />
				<?php endforeach; ?>

				<div class="rses-pager-row">
					<label class="rses-pager-density">
						<span class="rses-pager-label"><?php esc_html_e( 'Por página', 'relatasoft-secure-election-suite' ); ?></span>
						<select name="<?php echo esc_attr( $pp_key ); ?>" onchange="var i=this.form.querySelector('[name=\'<?php echo esc_js( $page_key ); ?>\']'); if(i){i.value='1';} this.form.submit();">
							<?php foreach ( RegistryListPager::PER_PAGE_OPTIONS as $opt ) : ?>
								<option value="<?php echo esc_attr( (string) $opt ); ?>" <?php selected( $per_page, $opt ); ?>>
									<?php echo esc_html( (string) $opt ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>

					<p class="rses-pager-range">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: first row on page, 2: last row on page, 3: total rows */
								__( 'A mostrar %1$d–%2$d de %3$d', 'relatasoft-secure-election-suite' ),
								$from,
								$to,
								$total
							)
						);
						?>
					</p>

					<div class="rses-pager-nav" role="group" aria-label="<?php esc_attr_e( 'Navegação de páginas', 'relatasoft-secure-election-suite' ); ?>">
						<a class="button rses-pager-btn<?php echo 1 === $page ? ' disabled' : ''; ?>" href="<?php echo 1 === $page ? '#' : esc_url( $first_url ); ?>" <?php echo 1 === $page ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
							<?php esc_html_e( 'Primeira', 'relatasoft-secure-election-suite' ); ?>
						</a>
						<a class="button rses-pager-btn<?php echo 1 === $page ? ' disabled' : ''; ?>" href="<?php echo 1 === $page ? '#' : esc_url( $prev_url ); ?>" <?php echo 1 === $page ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
							<?php esc_html_e( 'Anterior', 'relatasoft-secure-election-suite' ); ?>
						</a>
						<label class="rses-pager-jump">
							<span class="screen-reader-text"><?php esc_html_e( 'Ir para a página', 'relatasoft-secure-election-suite' ); ?></span>
							<input
								type="number"
								name="<?php echo esc_attr( $page_key ); ?>"
								min="1"
								max="<?php echo esc_attr( (string) $total_pages ); ?>"
								value="<?php echo esc_attr( (string) $page ); ?>"
								inputmode="numeric"
							/>
							<span class="rses-pager-of"><?php echo esc_html( sprintf( /* translators: %d: total pages */ __( 'de %d', 'relatasoft-secure-election-suite' ), $total_pages ) ); ?></span>
						</label>
						<button type="submit" class="button rses-pager-btn"><?php esc_html_e( 'Ir', 'relatasoft-secure-election-suite' ); ?></button>
						<a class="button rses-pager-btn<?php echo $page >= $total_pages ? ' disabled' : ''; ?>" href="<?php echo $page >= $total_pages ? '#' : esc_url( $next_url ); ?>" <?php echo $page >= $total_pages ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
							<?php esc_html_e( 'Próxima', 'relatasoft-secure-election-suite' ); ?>
						</a>
						<a class="button rses-pager-btn<?php echo $page >= $total_pages ? ' disabled' : ''; ?>" href="<?php echo $page >= $total_pages ? '#' : esc_url( $last_url ); ?>" <?php echo $page >= $total_pages ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
							<?php esc_html_e( 'Última', 'relatasoft-secure-election-suite' ); ?>
						</a>
					</div>
				</div>
			</form>
		</nav>
		<?php
	}

	public static function handle_create(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 'rses_create_registry_user' );
		$login = isset( $_POST['user_login'] ) ? (string) wp_unslash( $_POST['user_login'] ) : '';
		$email = isset( $_POST['user_email'] ) ? (string) wp_unslash( $_POST['user_email'] ) : '';
		$name  = isset( $_POST['display_name'] ) ? (string) wp_unslash( $_POST['display_name'] ) : '';
		$pass  = isset( $_POST['user_pass'] ) ? (string) wp_unslash( $_POST['user_pass'] ) : '';
		$role  = isset( $_POST['user_role'] ) ? sanitize_key( (string) wp_unslash( $_POST['user_role'] ) ) : '';

		$result = UserRegistryService::create_user( $login, $email, $name, $pass, $role );
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
		} else {
			$msg = __( 'Usuário cadastrado.', 'relatasoft-secure-election-suite' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=rses-electoral-roll&ve_notice=' . rawurlencode( $msg ) ) );
		exit;
	}
}
