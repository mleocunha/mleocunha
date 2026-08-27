<?php
/**
 * Estatísticas simples para persona Auditor (modo votação).
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Painel\Core\PainelKernel;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\AccessPolicy;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\Persona;
use RelataSoft\SecureElectionSuite\Voting\ElectionRepository;
use RelataSoft\SecureElectionSuite\Voting\EncryptedVoteRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Best-effort voting stats panel for auditors.
 */
class AuditorDashboardPage {

	/**
	 * @return array{
	 *   elections_open:int,
	 *   elections_closed:int,
	 *   rounds_open:int,
	 *   rounds_closed:int,
	 *   voters_total:int,
	 *   voters_voted:int,
	 *   voters_remaining:int,
	 *   abstentions_closed:int
	 * }
	 */
	public static function rses_collect_stats(): array {
		$stats = array(
			'elections_open'      => 0,
			'elections_closed'    => 0,
			'rounds_open'         => 0,
			'rounds_closed'       => 0,
			'voters_total'        => 0,
			'voters_voted'        => 0,
			'voters_remaining'    => 0,
			'abstentions_closed'  => 0,
		);

		if ( ! class_exists( ElectionRepository::class ) ) {
			return $stats;
		}

		$elections = ElectionRepository::rses_list();
		if ( ! is_array( $elections ) ) {
			$elections = array();
		}

		foreach ( $elections as $election ) {
			$status = (string) ( $election->status ?? '' );
			if ( in_array( $status, array( 'open', 'voting' ), true ) ) {
				++$stats['elections_open'];
			} elseif ( in_array( $status, array( 'closed', 'tallied', 'certified' ), true ) ) {
				++$stats['elections_closed'];
			}

			$rounds = ElectionRepository::rses_get_rounds( (int) $election->id );
			foreach ( $rounds as $round ) {
				$rstatus = (string) ( $round->status ?? '' );
				$rid     = (int) $round->id;
				$voted   = EncryptedVoteRepository::rses_count_distinct_voters( $rid );
				if ( in_array( $rstatus, array( 'open', 'voting' ), true ) ) {
					++$stats['rounds_open'];
					$stats['voters_voted'] += $voted;
				} elseif ( in_array( $rstatus, array( 'closed', 'tallied' ), true ) ) {
					++$stats['rounds_closed'];
					$stats['voters_voted'] += $voted;
					// Abstention estimate: enrolled subscribers minus distinct voters (best-effort).
					$enrolled = self::rses_count_subscribers();
					$stats['abstentions_closed'] += max( 0, $enrolled - $voted );
				}
			}
		}

		$stats['voters_total']     = self::rses_count_subscribers();
		$stats['voters_remaining'] = max( 0, $stats['voters_total'] - $stats['voters_voted'] );
		return $stats;
	}

	private static function rses_count_subscribers(): int {
		$counts = count_users();
		$avail  = is_array( $counts['avail_roles'] ?? null ) ? $counts['avail_roles'] : array();
		return (int) ( $avail['subscriber'] ?? 0 );
	}

	/**
	 * Render stats section (embedded on dashboard when auditor + voting).
	 */
	public static function rses_render_section(): void {
		if ( ! ModeLock::rses_is_mode( ModeLock::RSES_MODE_VOTING ) ) {
			return;
		}

		$kernel  = PainelKernel::instance();
		$persona = $kernel ? $kernel->permissions->currentPersona() : Persona::Eleitor;
		$policy  = $kernel ? $kernel->accessPolicy : new AccessPolicy();
		if ( ! $policy->can( $persona, AccessPolicy::PERM_VOTING_STATS_VIEW ) ) {
			return;
		}
		// Prefer showing this panel for Auditor; admins/gestor also have the perm but cards already cover them.
		if ( Persona::Auditor !== $persona ) {
			return;
		}

		$s = self::rses_collect_stats();
		?>
		<section class="rses-panel rses-panel-card rses-auditor-stats" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-panel-header">
				<p class="rses-panel-kicker"><?php esc_html_e( 'Auditoria', 'relatasoft-secure-election-suite' ); ?></p>
				<h2 class="rses-panel-title"><?php esc_html_e( 'Estatísticas de votação', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="rses-panel-desc"><?php esc_html_e( 'Contagens aproximadas a partir dos repositórios existentes (melhor esforço).', 'relatasoft-secure-election-suite' ); ?></p>
			</header>
			<ul class="rses-electoral-meta">
				<li><span class="rses-electoral-meta-label"><?php esc_html_e( 'Eleições abertas', 'relatasoft-secure-election-suite' ); ?></span> <strong><?php echo esc_html( (string) $s['elections_open'] ); ?></strong></li>
				<li><span class="rses-electoral-meta-label"><?php esc_html_e( 'Eleições fechadas', 'relatasoft-secure-election-suite' ); ?></span> <strong><?php echo esc_html( (string) $s['elections_closed'] ); ?></strong></li>
				<li><span class="rses-electoral-meta-label"><?php esc_html_e( 'Rodadas abertas', 'relatasoft-secure-election-suite' ); ?></span> <strong><?php echo esc_html( (string) $s['rounds_open'] ); ?></strong></li>
				<li><span class="rses-electoral-meta-label"><?php esc_html_e( 'Rodadas fechadas', 'relatasoft-secure-election-suite' ); ?></span> <strong><?php echo esc_html( (string) $s['rounds_closed'] ); ?></strong></li>
				<li><span class="rses-electoral-meta-label"><?php esc_html_e( 'Eleitores no cadastro', 'relatasoft-secure-election-suite' ); ?></span> <strong><?php echo esc_html( (string) $s['voters_total'] ); ?></strong></li>
				<li><span class="rses-electoral-meta-label"><?php esc_html_e( 'Já votaram (distintos)', 'relatasoft-secure-election-suite' ); ?></span> <strong><?php echo esc_html( (string) $s['voters_voted'] ); ?></strong></li>
				<li><span class="rses-electoral-meta-label"><?php esc_html_e( 'Restantes (estimativa)', 'relatasoft-secure-election-suite' ); ?></span> <strong><?php echo esc_html( (string) $s['voters_remaining'] ); ?></strong></li>
				<li><span class="rses-electoral-meta-label"><?php esc_html_e( 'Abstenções (rodadas fechadas)', 'relatasoft-secure-election-suite' ); ?></span> <strong><?php echo esc_html( (string) $s['abstentions_closed'] ); ?></strong></li>
			</ul>
		</section>
		<?php
	}
}
