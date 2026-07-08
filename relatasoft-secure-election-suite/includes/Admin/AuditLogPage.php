<?php
/**
 * Audit log admin page.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\Security\AuditLogger;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Audit log viewer.
 */
class AuditLogPage {

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
}
