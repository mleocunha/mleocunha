<?php
/**
 * Polling station admin: electors, export.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Node 2 operational tools.
 */
class EVote_Polling_Admin {

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_action( 'admin_post_evote_import_electors', array( __CLASS__, 'handle_import_electors' ) );
		add_action( 'admin_post_evote_download_ballot_export', array( __CLASS__, 'handle_download_export' ) );
	}

	/**
	 * Export ballots page.
	 */
	public static function render_export_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$runnings = get_posts(
			array(
				'post_type'      => 'evote_running',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$selected = isset( $_GET['running_id'] ) ? absint( $_GET['running_id'] ) : 0;
		$error    = isset( $_GET['evote_error'] ) ? sanitize_text_field( wp_unslash( $_GET['evote_error'] ) ) : '';
		$notice   = isset( $_GET['evote_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['evote_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Polling Station Tools', 'decentralized-evoting' ); ?></h1>
			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Import elector tokens', 'decentralized-evoting' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'evote_import_electors' ); ?>
				<input type="hidden" name="action" value="evote_import_electors" />
				<p>
					<label for="import_running_id"><strong><?php esc_html_e( 'Running', 'decentralized-evoting' ); ?></strong></label>
					<select name="running_id" id="import_running_id" required>
						<option value=""><?php esc_html_e( '— Select —', 'decentralized-evoting' ); ?></option>
						<?php foreach ( $runnings as $r ) : ?>
							<option value="<?php echo esc_attr( (string) $r->ID ); ?>" <?php selected( $selected, $r->ID ); ?>><?php echo esc_html( $r->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="evote_tokens"><strong><?php esc_html_e( 'Tokens (one per line)', 'decentralized-evoting' ); ?></strong></label>
					<textarea name="evote_tokens" id="evote_tokens" class="large-text code" rows="10" placeholder="token-abc&#10;token-def"></textarea>
					<span class="description"><?php esc_html_e( 'Only SHA-256 hashes are stored. Plain tokens are never saved.', 'decentralized-evoting' ); ?></span>
				</p>
				<?php submit_button( __( 'Import electors', 'decentralized-evoting' ) ); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Export encrypted ballot box', 'decentralized-evoting' ); ?></h2>
			<p><?php esc_html_e( 'Transfer the JSON file to the tally board (Node 3). Includes checksum for integrity.', 'decentralized-evoting' ); ?></p>
			<?php if ( $selected ) : ?>
				<?php
				$stats = EVote_Ballot_Repository::count_stats( $selected );
				?>
				<p>
					<?php
					printf(
						/* translators: 1: electors, 2: voted, 3: ballots */
						esc_html__( 'Stats: %1$d electors, %2$d voted, %3$d encrypted ballot records.', 'decentralized-evoting' ),
						(int) $stats['electors'],
						(int) $stats['voted'],
						(int) $stats['ballots']
					);
					?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( self::export_download_url( $selected ) ); ?>">
						<?php esc_html_e( 'Download ballot export (JSON)', 'decentralized-evoting' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<hr />
			<h2><?php esc_html_e( 'Voting page', 'decentralized-evoting' ); ?></h2>
			<p><?php esc_html_e( 'Add this shortcode to a page:', 'decentralized-evoting' ); ?> <code>[evote_poll id="<?php echo esc_attr( $selected ? (string) $selected : 'RUNNING_ID' ); ?>"]</code></p>
		</div>
		<?php
	}

	/**
	 * @param int $running_id Running ID.
	 * @return string
	 */
	public static function export_download_url( $running_id ) {
		return add_query_arg(
			array(
				'action'     => 'evote_download_ballot_export',
				'running_id' => absint( $running_id ),
				'_wpnonce'   => wp_create_nonce( 'evote_download_ballot_export' ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Import electors handler.
	 */
	public static function handle_import_electors() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'decentralized-evoting' ) );
		}
		check_admin_referer( 'evote_import_electors' );

		$running_id = isset( $_POST['running_id'] ) ? absint( $_POST['running_id'] ) : 0;
		$raw        = isset( $_POST['evote_tokens'] ) ? wp_unslash( $_POST['evote_tokens'] ) : '';
		$tokens     = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $raw ) ) );

		$result = EVote_Ballot_Repository::import_electors( $running_id, $tokens );
		if ( is_wp_error( $result ) ) {
			self::redirect_tools( $running_id, $result->get_error_message() );
		}

		$msg = sprintf(
			/* translators: 1: imported count, 2: skipped count */
			__( 'Imported %1$d electors (%2$d duplicates skipped).', 'decentralized-evoting' ),
			$result['imported'],
			$result['skipped']
		);
		self::redirect_tools( $running_id, '', $msg );
	}

	/**
	 * Download export JSON.
	 */
	public static function handle_download_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'decentralized-evoting' ) );
		}
		check_admin_referer( 'evote_download_ballot_export' );

		$running_id = isset( $_GET['running_id'] ) ? absint( $_GET['running_id'] ) : 0;
		$export     = EVote_Json_Export::ballot_box( $running_id );
		if ( is_wp_error( $export ) ) {
			wp_die( esc_html( $export->get_error_message() ) );
		}

		$json     = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$filename = 'evote-ballot-export-' . $running_id . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * @param int    $running_id Running ID.
	 * @param string $error      Error message.
	 * @param string $notice     Success notice.
	 */
	private static function redirect_tools( $running_id, $error = '', $notice = '' ) {
		$args = array( 'page' => 'evote-export' );
		if ( $running_id ) {
			$args['running_id'] = $running_id;
		}
		if ( $error ) {
			$args['evote_error'] = rawurlencode( $error );
		}
		if ( $notice ) {
			$args['evote_notice'] = rawurlencode( $notice );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
