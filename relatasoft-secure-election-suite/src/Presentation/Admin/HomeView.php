<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Presentation\Admin;

use RelataSoft\SecureElectionSuite\Admin\Brand;
use RelataSoft\SecureElectionSuite\Admin\ModeSetupPage;
use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Frontend\JourneySettings;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Painel\Core\PainelKernel;

final class HomeView {

	public static function render(): void {
		$kernel = PainelKernel::instance();
		if ( ! ModeLock::rses_has_mode() ) {
			ModeSetupPage::rses_render();
			return;
		}

		$mode  = ModeLock::rses_get_mode();
		$cards = $kernel ? $kernel->dashboardHome->cardsForMode( $mode ) : array();
		$product = $kernel ? $kernel->loginBranding->productName() : 'Voto Eletrônico by RelataSoft';
		$panel   = $kernel ? $kernel->loginBranding->panelName() : 'Painel de Controle Eleitoral';
		?>
		<div class="wrap rses-wrap rses-screen ve-painel-home" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand ve-painel-hero">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php echo esc_html( $product ); ?></p>
				<h1 class="rses-hero-title"><?php echo esc_html( $panel ); ?></h1>
				<p class="rses-hero-lead">
					<?php esc_html_e( 'Gestão democrática, auditável e criptograficamente garantida dos processos eleitorais.', 'relatasoft-secure-election-suite' ); ?>
				</p>
			</header>

			<div class="rses-panel rses-panel-info ve-painel-mode-chip">
				<p>
					<strong><?php esc_html_e( 'Modo ativo:', 'relatasoft-secure-election-suite' ); ?></strong>
					<?php echo esc_html( ModeLock::rses_get_mode_label( $mode ) ); ?>
				</p>
				<?php
				$cliente_id   = JourneySettings::rses_cliente_id();
				$cliente_nome = JourneySettings::rses_cliente_nome();
				if ( '' !== $cliente_id || '' !== $cliente_nome ) :
					?>
					<p>
						<strong><?php esc_html_e( 'Cliente:', 'relatasoft-secure-election-suite' ); ?></strong>
						<?php
						echo esc_html(
							trim(
								( '' !== $cliente_nome ? $cliente_nome : '' )
								. ( '' !== $cliente_id ? ( ( '' !== $cliente_nome ? ' · ' : '' ) . $cliente_id ) : '' )
							)
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<div class="rses-dashboard-grid ve-painel-home-grid">
				<?php foreach ( $cards as $card ) : ?>
					<div class="rses-dashboard-card ve-painel-card">
						<h2><?php echo esc_html( $card->title ); ?></h2>
						<p><?php echo esc_html( $card->body ); ?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . rawurlencode( $card->actionSlug ) ) ); ?>">
								<?php echo esc_html( $card->actionLabel ); ?>
							</a>
						</p>
					</div>
				<?php endforeach; ?>
			</div>

			<?php
			if ( class_exists( \RelataSoft\SecureElectionSuite\Admin\AuditorDashboardPage::class ) ) {
				\RelataSoft\SecureElectionSuite\Admin\AuditorDashboardPage::rses_render_section();
			}
			?>
		</div>
		<?php
	}
}
