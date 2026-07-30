<?php
/**
 * Security submenu: Monitor and Secure Voting.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Bootstrap\ModeLock;
use RelataSoft\SecureElectionSuite\Frontend\JourneySettings;
use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Security\AccessControl;
use RelataSoft\SecureElectionSuite\Security\AdminUrlHardener;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\LoginTracker;
use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\Voting\ElectionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Voting-mode security operations console.
 */
class SecurityMonitorPage {

	/**
	 * Security-related audit actions shown in the scrollable log.
	 *
	 * @var list<string>
	 */
	private static array $rses_security_actions = array(
		'elector_login',
		'force_logout',
		'user_ban',
		'user_unban',
		'url_hardening_saved',
		'security_monitor_view',
	);

	/**
	 * Register handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_force_logout', array( self::class, 'rses_handle_force_logout' ) );
		add_action( 'admin_post_rses_unban_user', array( self::class, 'rses_handle_unban' ) );
		add_action( 'admin_post_rses_save_url_hardening', array( self::class, 'rses_handle_url_hardening' ) );
	}

	/**
	 * Render page.
	 */
	public static function rses_render(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector.
		$rses_round_id = isset( $_GET['rses_round_id'] ) ? absint( $_GET['rses_round_id'] ) : 0;
		$rses_metrics  = LoginTracker::rses_metrics( $rses_round_id );
		$rses_elections = ElectionRepository::rses_list();
		$rses_settings  = JourneySettings::rses_get();
		$rses_hardening = ! empty( $rses_settings['url_hardening_enabled'] );
		$rses_users     = self::rses_user_rows( $rses_round_id );
		$rses_log       = self::rses_security_log_entries( 250 );
		$rses_elector_label = RoleLabels::rses_elector_plural();
		?>
		<div class="wrap rses-wrap rses-screen rses-security-monitor" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Security', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Monitor and Secure Voting', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Watch elector access, force logout, optionally bar new logins, and review the hashed security action log.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<?php if ( ! empty( $_GET['rses_done'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="rses-panel rses-panel-success"><p><?php esc_html_e( 'Security action completed and recorded in the hash chain.', 'relatasoft-secure-election-suite' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['rses_url_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="rses-panel rses-panel-success"><p><?php esc_html_e( 'URL hardening settings saved. Permalinks were flushed.', 'relatasoft-secure-election-suite' ); ?></p></div>
			<?php endif; ?>

			<section class="rses-panel rses-panel-card">
				<h2 class="rses-panel-title"><?php esc_html_e( 'Entry URLs', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="rses-panel-desc">
					<?php
					printf(
						/* translators: 1: central slug, 2: id.php slug */
						esc_html__( 'When enabled in Voting Platform mode, the admin area is served at /%1$s and the sign-in screen at /%2$s. Direct /wp-admin and wp-login.php entry points are redirected or blocked.', 'relatasoft-secure-election-suite' ),
						esc_html( AdminUrlHardener::ADMIN_SLUG ),
						esc_html( AdminUrlHardener::LOGIN_SLUG )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-form">
					<?php Nonce::rses_field( Nonce::RSES_ACTION_URL_HARDENING ); ?>
					<input type="hidden" name="action" value="rses_save_url_hardening" />
					<label class="rses-check-row">
						<input type="checkbox" name="rses_url_hardening_enabled" value="1" <?php checked( $rses_hardening ); ?> />
						<?php esc_html_e( 'Enable URL hardening (/central and /id.php)', 'relatasoft-secure-election-suite' ); ?>
					</label>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: admin URL, 2: login URL */
								__( 'Current public URLs: %1$s · %2$s', 'relatasoft-secure-election-suite' ),
								home_url( '/' . AdminUrlHardener::ADMIN_SLUG ),
								home_url( '/' . AdminUrlHardener::LOGIN_SLUG )
							)
						);
						?>
					</p>
					<?php submit_button( __( 'Save URL settings', 'relatasoft-secure-election-suite' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>

			<section class="rses-panel rses-panel-card">
				<div class="rses-panel-header">
					<h2 class="rses-panel-title"><?php esc_html_e( 'Live metrics', 'relatasoft-secure-election-suite' ); ?></h2>
				</div>

				<form method="get" action="" class="rses-form rses-security-election-filter">
					<input type="hidden" name="page" value="rses-security" />
					<label for="rses_round_id"><strong><?php esc_html_e( 'Election / round', 'relatasoft-secure-election-suite' ); ?></strong></label>
					<select name="rses_round_id" id="rses_round_id" onchange="this.form.submit()">
						<option value="0"><?php esc_html_e( '— Select a voting round —', 'relatasoft-secure-election-suite' ); ?></option>
						<?php foreach ( $rses_elections as $rses_election ) : ?>
							<?php
							$rses_rounds = ElectionRepository::rses_get_rounds( (int) $rses_election->id );
							foreach ( $rses_rounds as $rses_round ) :
								$rses_label = sprintf(
									'#%1$d %2$s — %3$s (r%4$d, %5$s)',
									(int) $rses_election->id,
									(string) $rses_election->title,
									(string) $rses_round->title,
									(int) $rses_round->round_number,
									(string) $rses_round->status
								);
								?>
								<option value="<?php echo esc_attr( (string) $rses_round->id ); ?>" <?php selected( $rses_round_id, (int) $rses_round->id ); ?>>
									<?php echo esc_html( $rses_label ); ?>
								</option>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</select>
				</form>

				<div class="rses-security-metrics">
					<article class="rses-security-metric">
						<p class="rses-security-metric__label"><?php echo esc_html( sprintf( /* translators: %s: Electors role label */ __( '%s who entered the system', 'relatasoft-secure-election-suite' ), $rses_elector_label ) ); ?></p>
						<p class="rses-security-metric__value"><?php echo esc_html( (string) $rses_metrics['electors_logged_in'] ); ?></p>
						<p class="rses-security-metric__hint"><?php echo esc_html( sprintf( /* translators: %d: enrolled electors */ __( 'of %d enrolled', 'relatasoft-secure-election-suite' ), $rses_metrics['electors_total'] ) ); ?></p>
					</article>

					<article class="rses-security-metric">
						<p class="rses-security-metric__label"><?php esc_html_e( 'Entered since selected voting opened', 'relatasoft-secure-election-suite' ); ?></p>
						<p class="rses-security-metric__value"><?php echo esc_html( (string) $rses_metrics['electors_since_open'] ); ?></p>
						<p class="rses-security-metric__hint">
							<?php
							if ( $rses_metrics['opened_at'] ) {
								echo esc_html( sprintf( /* translators: %s: datetime */ __( 'Opened at %s (UTC)', 'relatasoft-secure-election-suite' ), $rses_metrics['opened_at'] ) );
							} else {
								esc_html_e( 'Select an opened round to compute this set.', 'relatasoft-secure-election-suite' );
							}
							?>
						</p>
					</article>

					<article class="rses-security-metric">
						<p class="rses-security-metric__label"><?php esc_html_e( 'Of that set: already voted', 'relatasoft-secure-election-suite' ); ?></p>
						<p class="rses-security-metric__value"><?php echo esc_html( (string) $rses_metrics['electors_since_open_voted'] ); ?></p>
						<p class="rses-security-metric__hint"><?php echo esc_html( sprintf( /* translators: %d: online count */ __( 'Currently online in that set: %d', 'relatasoft-secure-election-suite' ), $rses_metrics['electors_since_open_online'] ) ); ?></p>
					</article>
				</div>
			</section>

			<section class="rses-panel rses-panel-card">
				<h2 class="rses-panel-title"><?php esc_html_e( 'Users — force logout / ban', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="rses-panel-desc"><?php esc_html_e( 'Force logout destroys every active session. You will be asked whether to also bar new logins for the expelled account.', 'relatasoft-secure-election-suite' ); ?></p>

				<div class="rses-security-table-scroll">
					<table class="widefat striped rses-security-users">
						<thead>
							<tr>
								<th><?php esc_html_e( 'User', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Roles', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Last login (UTC)', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Session', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Ban', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'relatasoft-secure-election-suite' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $rses_users ) ) : ?>
								<tr><td colspan="6"><?php esc_html_e( 'No users found.', 'relatasoft-secure-election-suite' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $rses_users as $rses_row ) : ?>
									<tr>
										<td>
											<strong><?php echo esc_html( $rses_row['login'] ); ?></strong>
											<br /><span class="description">#<?php echo esc_html( (string) $rses_row['id'] ); ?> · <?php echo esc_html( $rses_row['email'] ); ?></span>
										</td>
										<td><?php echo esc_html( $rses_row['roles'] ); ?></td>
										<td><?php echo esc_html( $rses_row['last_login'] ?: '—' ); ?></td>
										<td><?php echo $rses_row['online'] ? esc_html__( 'Online', 'relatasoft-secure-election-suite' ) : esc_html__( 'Offline', 'relatasoft-secure-election-suite' ); ?></td>
										<td><?php echo $rses_row['banned'] ? esc_html__( 'Barred', 'relatasoft-secure-election-suite' ) : esc_html__( 'Allowed', 'relatasoft-secure-election-suite' ); ?></td>
										<td class="rses-security-actions">
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-inline-form rses-force-logout-form">
												<?php Nonce::rses_field( Nonce::RSES_ACTION_FORCE_LOGOUT ); ?>
												<input type="hidden" name="action" value="rses_force_logout" />
												<input type="hidden" name="rses_user_id" value="<?php echo esc_attr( (string) $rses_row['id'] ); ?>" />
												<input type="hidden" name="rses_round_id" value="<?php echo esc_attr( (string) $rses_round_id ); ?>" />
												<input type="hidden" name="rses_also_ban" value="0" class="rses-also-ban-field" />
												<button type="submit" class="button button-secondary rses-force-logout-btn"><?php esc_html_e( 'Force logout', 'relatasoft-secure-election-suite' ); ?></button>
											</form>
											<?php if ( $rses_row['banned'] ) : ?>
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-inline-form">
													<?php Nonce::rses_field( Nonce::RSES_ACTION_UNBAN_USER ); ?>
													<input type="hidden" name="action" value="rses_unban_user" />
													<input type="hidden" name="rses_user_id" value="<?php echo esc_attr( (string) $rses_row['id'] ); ?>" />
													<input type="hidden" name="rses_round_id" value="<?php echo esc_attr( (string) $rses_round_id ); ?>" />
													<button type="submit" class="button"><?php esc_html_e( 'Lift ban', 'relatasoft-secure-election-suite' ); ?></button>
												</form>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</section>

			<section class="rses-panel rses-panel-card">
				<h2 class="rses-panel-title"><?php esc_html_e( 'Security action log', 'relatasoft-secure-election-suite' ); ?></h2>
				<p class="rses-panel-desc"><?php esc_html_e( 'Hash-chained record of security commands: origin command, ordering user, and entry hash.', 'relatasoft-secure-election-suite' ); ?></p>
				<div class="rses-security-table-scroll rses-security-log-scroll">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Time (UTC)', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Ordered by', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Action', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Origin command', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Target', 'relatasoft-secure-election-suite' ); ?></th>
								<th><?php esc_html_e( 'Hash', 'relatasoft-secure-election-suite' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $rses_log ) ) : ?>
								<tr><td colspan="6"><?php esc_html_e( 'No security actions recorded yet.', 'relatasoft-secure-election-suite' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $rses_log as $rses_entry ) : ?>
									<?php
									$payload = json_decode( (string) $rses_entry->payload_json, true );
									$payload = is_array( $payload ) ? $payload : array();
									$command = isset( $payload['command'] ) ? (string) $payload['command'] : $rses_entry->action;
									$actor   = absint( $rses_entry->actor_user_id );
									$actor_u = $actor > 0 ? get_userdata( $actor ) : null;
									$actor_l = ( $actor_u && $actor_u->exists() ) ? $actor_u->user_login : (string) $actor;
									$target  = isset( $payload['target_login'] ) ? (string) $payload['target_login'] : ( $rses_entry->object_id ? '#' . $rses_entry->object_id : '—' );
									?>
									<tr>
										<td><?php echo esc_html( (string) $rses_entry->created_at ); ?></td>
										<td><?php echo esc_html( $actor_l ); ?></td>
										<td><code><?php echo esc_html( (string) $rses_entry->action ); ?></code></td>
										<td><code><?php echo esc_html( $command ); ?></code></td>
										<td><?php echo esc_html( $target ); ?></td>
										<td><code title="<?php echo esc_attr( (string) $rses_entry->current_hash ); ?>"><?php echo esc_html( substr( (string) $rses_entry->current_hash, 0, 20 ) . '…' ); ?></code></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Rows for the user operations table.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function rses_user_rows( int $round_id ): array {
		unset( $round_id );

		$users = get_users(
			array(
				'orderby' => 'login',
				'order'   => 'ASC',
				'number'  => 500,
			)
		);

		$rows = array();
		foreach ( $users as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$roles = array_map( 'strval', (array) $user->roles );
			$rows[] = array(
				'id'         => (int) $user->ID,
				'login'      => (string) $user->user_login,
				'email'      => (string) $user->user_email,
				'roles'      => implode( ', ', $roles ),
				'last_login' => LoginTracker::rses_last_login_at( (int) $user->ID ),
				'online'     => LoginTracker::rses_has_active_session( (int) $user->ID ),
				'banned'     => AccessControl::rses_is_banned( (int) $user->ID ),
			);
		}

		return $rows;
	}

	/**
	 * Filtered audit entries for the security console.
	 *
	 * @return array<int,object>
	 */
	private static function rses_security_log_entries( int $limit = 250 ): array {
		$entries = AuditLogger::rses_get_entries( max( 500, $limit ) );
		$out     = array();

		foreach ( $entries as $entry ) {
			if ( ! in_array( (string) $entry->action, self::$rses_security_actions, true ) ) {
				continue;
			}
			$out[] = $entry;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Force logout (+ optional ban) handler.
	 */
	public static function rses_handle_force_logout(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_FORCE_LOGOUT );

		$user_id  = isset( $_POST['rses_user_id'] ) ? absint( $_POST['rses_user_id'] ) : 0;
		$also_ban = ! empty( $_POST['rses_also_ban'] );
		$round_id = isset( $_POST['rses_round_id'] ) ? absint( $_POST['rses_round_id'] ) : 0;

		if ( $user_id > 0 ) {
			AccessControl::rses_force_logout( $user_id, $also_ban, 'monitor_force_logout' );
		}

		self::rses_redirect_done( $round_id );
	}

	/**
	 * Lift ban handler.
	 */
	public static function rses_handle_unban(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_UNBAN_USER );

		$user_id  = isset( $_POST['rses_user_id'] ) ? absint( $_POST['rses_user_id'] ) : 0;
		$round_id = isset( $_POST['rses_round_id'] ) ? absint( $_POST['rses_round_id'] ) : 0;

		if ( $user_id > 0 ) {
			AccessControl::rses_unban_user( $user_id, 'monitor_unban' );
		}

		self::rses_redirect_done( $round_id );
	}

	/**
	 * Save URL hardening toggle.
	 */
	public static function rses_handle_url_hardening(): void {
		Capability::rses_require_admin();
		ModeLock::rses_require_mode( ModeLock::RSES_MODE_VOTING );
		Nonce::rses_verify_or_die( Nonce::RSES_ACTION_URL_HARDENING );

		$enabled = ! empty( $_POST['rses_url_hardening_enabled'] );
		JourneySettings::rses_save( array( 'url_hardening_enabled' => $enabled ) );

		AdminUrlHardener::rses_flush_rules();

		AuditLogger::rses_log(
			'url_hardening_saved',
			'settings',
			null,
			array(
				'command'    => 'save_url_hardening',
				'ordered_by' => get_current_user_id(),
				'enabled'    => $enabled,
				'admin_slug' => AdminUrlHardener::ADMIN_SLUG,
				'login_slug' => AdminUrlHardener::LOGIN_SLUG,
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'rses-security',
					'rses_url_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Redirect back to the monitor after a mutating action.
	 */
	private static function rses_redirect_done( int $round_id ): void {
		$args = array(
			'page'     => 'rses-security',
			'rses_done' => '1',
		);
		if ( $round_id > 0 ) {
			$args['rses_round_id'] = $round_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
