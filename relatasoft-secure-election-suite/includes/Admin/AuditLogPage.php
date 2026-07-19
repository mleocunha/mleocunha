<?php
/**
 * Audit log admin page.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Audit log viewer.
 */
class AuditLogPage {

	/**
	 * Register admin-post handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_rses_repair_audit_chain', array( self::class, 'rses_handle_repair' ) );
	}

	/**
	 * Render audit log page.
	 */
	public static function rses_render(): void {
		Capability::rses_require_admin();

		$rses_entries = AuditLogger::rses_get_entries( 200 );
		$rses_chain   = AuditLogger::rses_verify_chain();
		?>
		<div class="wrap rses-wrap">
			<h1><?php esc_html_e( 'Audit Log', 'relatasoft-secure-election-suite' ); ?></h1>

			<?php if ( ! empty( $_GET['rses_repaired'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Audit hash chain recomputed successfully.', 'relatasoft-secure-election-suite' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="rses-notice <?php echo $rses_chain['valid'] ? 'rses-notice-success' : 'rses-notice-error'; ?>">
				<p>
					<?php
					echo $rses_chain['valid']
						? esc_html__( 'Hash chain integrity: VALID', 'relatasoft-secure-election-suite' )
						: esc_html__( 'Hash chain integrity: INVALID', 'relatasoft-secure-election-suite' );
					?>
				</p>
				<?php if ( ! empty( $rses_chain['errors'] ) ) : ?>
					<ul>
						<?php foreach ( $rses_chain['errors'] as $rses_error ) : ?>
							<li><?php echo esc_html( $rses_error ); ?></li>
						<?php endforeach; ?>
					</ul>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php Nonce::rses_field( 'rses_repair_audit_chain' ); ?>
						<input type="hidden" name="action" value="rses_repair_audit_chain" />
						<p class="description">
							<?php esc_html_e( 'If this installation was upgraded, recompute hashes to align stored values with the canonical verifier (payloads are not modified).', 'relatasoft-secure-election-suite' ); ?>
						</p>
						<?php submit_button( __( 'Repair Hash Chain', 'relatasoft-secure-election-suite' ), 'secondary' ); ?>
					</form>
				<?php endif; ?>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'relatasoft-secure-election-suite' ); ?></th>
						<th><?php esc_html_e( 'Time', 'relatasoft-secure-election-suite' ); ?></th>
						<th><?php esc_html_e( 'Actor', 'relatasoft-secure-election-suite' ); ?></th>
						<th><?php esc_html_e( 'Action', 'relatasoft-secure-election-suite' ); ?></th>
						<th><?php esc_html_e( 'Object', 'relatasoft-secure-election-suite' ); ?></th>
						<th><?php esc_html_e( 'Hash', 'relatasoft-secure-election-suite' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rses_entries ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No audit entries.', 'relatasoft-secure-election-suite' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rses_entries as $rses_entry ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $rses_entry->id ); ?></td>
								<td><?php echo esc_html( $rses_entry->created_at ); ?></td>
								<td><?php echo esc_html( (string) $rses_entry->actor_user_id ); ?></td>
								<td><?php echo esc_html( $rses_entry->action ); ?></td>
								<td><?php echo esc_html( $rses_entry->object_type . ( $rses_entry->object_id ? ' #' . $rses_entry->object_id : '' ) ); ?></td>
								<td><code><?php echo esc_html( substr( $rses_entry->current_hash, 0, 16 ) . '...' ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle hash chain repair.
	 */
	public static function rses_handle_repair(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 'rses_repair_audit_chain' );

		$rses_result = AuditLogger::rses_repair_chain();

		AuditLogger::rses_log(
			'audit_chain_repair',
			'system',
			null,
			array(
				'repaired' => $rses_result['repaired'],
				'valid'    => $rses_result['valid'],
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=rses-audit-log&rses_repaired=1' ) );
		exit;
	}
}
