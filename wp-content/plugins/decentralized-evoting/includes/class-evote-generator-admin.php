<?php
/**
 * Node 1 (generator) admin — ElGamal keygen and SSS export.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authority site: key generation UI and secure JSON downloads.
 */
class EVote_Generator_Admin {

	const TRANSIENT_PREFIX = 'evote_gen_';
	const TRANSIENT_TTL    = 600;

	/**
	 * Register hooks.
	 */
	public static function register_hooks() {
		add_action( 'admin_post_evote_generate_keys', array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_evote_download_export', array( __CLASS__, 'handle_download' ) );
	}

	/**
	 * Key generation page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$defaults  = EVote_Crypto::default_sss_params();
		$token     = isset( $_GET['evote_batch'] ) ? sanitize_text_field( wp_unslash( $_GET['evote_batch'] ) ) : '';
		$package   = $token ? self::get_batch( $token ) : false;
		$error     = isset( $_GET['evote_error'] ) ? sanitize_text_field( wp_unslash( $_GET['evote_error'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ElGamal Key Generation', 'decentralized-evoting' ); ?></h1>
			<p><?php esc_html_e( 'Generate a modular ElGamal key pair (RFC 3526 Group 14) and split the private key with Shamir secret sharing. Export the public key for the polling station and one JSON file per trustee share.', 'decentralized-evoting' ); ?></p>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_array( $package ) ) : ?>
				<?php self::render_export_results( $token, $package ); ?>
			<?php else : ?>
				<?php self::render_generate_form( $defaults ); ?>
			<?php endif; ?>

			<hr />
			<h2><?php esc_html_e( 'Parameter limits', 'decentralized-evoting' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: min threshold, 2: min shares, 3: max shares */
					esc_html__( 'Supported: %1$d-of-%2$d minimum up to t-of-n where t ≤ n and n ≤ %3$d. Default: 3-of-5.', 'decentralized-evoting' ),
					EVote_Crypto::SSS_MIN_THRESHOLD,
					EVote_Crypto::SSS_MIN_SHARES,
					EVote_Crypto::SSS_MAX_SHARES
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * @param array{threshold: int, shares: int} $defaults Defaults.
	 */
	private static function render_generate_form( $defaults ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'evote_generate_keys' ); ?>
			<input type="hidden" name="action" value="evote_generate_keys" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="evote_threshold"><?php esc_html_e( 'Threshold (t)', 'decentralized-evoting' ); ?></label></th>
					<td>
						<input type="number" name="evote_threshold" id="evote_threshold" min="<?php echo esc_attr( (string) EVote_Crypto::SSS_MIN_THRESHOLD ); ?>" max="<?php echo esc_attr( (string) EVote_Crypto::SSS_MAX_SHARES ); ?>" value="<?php echo esc_attr( (string) $defaults['threshold'] ); ?>" required />
						<p class="description"><?php esc_html_e( 'Minimum shares required to reconstruct the private key.', 'decentralized-evoting' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="evote_shares"><?php esc_html_e( 'Total shares (n)', 'decentralized-evoting' ); ?></label></th>
					<td>
						<input type="number" name="evote_shares" id="evote_shares" min="<?php echo esc_attr( (string) EVote_Crypto::SSS_MIN_SHARES ); ?>" max="<?php echo esc_attr( (string) EVote_Crypto::SSS_MAX_SHARES ); ?>" value="<?php echo esc_attr( (string) $defaults['shares'] ); ?>" required />
						<p class="description"><?php esc_html_e( 'Number of trustee share files to generate.', 'decentralized-evoting' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Generate keys & shares', 'decentralized-evoting' ) ); ?>
		</form>
		<p class="description"><strong><?php esc_html_e( 'Security:', 'decentralized-evoting' ); ?></strong> <?php esc_html_e( 'The private key is never stored or displayed. Download all files before leaving this page; batch downloads expire after 10 minutes.', 'decentralized-evoting' ); ?></p>
		<?php
	}

	/**
	 * @param string               $token   Batch token.
	 * @param array<string, mixed> $package Export package.
	 */
	private static function render_export_results( $token, $package ) {
		$key_id = $package['key_id'] ?? '';
		?>
		<div class="notice notice-success">
			<p>
				<?php
				printf(
					/* translators: %s: key id */
					esc_html__( 'Key material generated. Key ID: %s', 'decentralized-evoting' ),
					esc_html( $key_id )
				);
				?>
			</p>
		</div>
		<h2><?php esc_html_e( 'Downloads', 'decentralized-evoting' ); ?></h2>
		<ul>
			<li>
				<a class="button button-primary" href="<?php echo esc_url( self::download_url( $token, 'public' ) ); ?>">
					<?php esc_html_e( 'Download public key (JSON)', 'decentralized-evoting' ); ?>
				</a>
			</li>
			<?php foreach ( $package['shares'] as $share ) : ?>
				<li>
					<a class="button" href="<?php echo esc_url( self::download_url( $token, 'share', (int) $share['share_index'] ) ); ?>">
						<?php
						printf(
							/* translators: %d: share index */
							esc_html__( 'Download share %d (JSON)', 'decentralized-evoting' ),
							(int) $share['share_index']
						);
						?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=evote-generator' ) ); ?>"><?php esc_html_e( 'Generate another key set', 'decentralized-evoting' ); ?></a></p>
		<?php
	}

	/**
	 * Handle key generation POST.
	 */
	public static function handle_generate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'decentralized-evoting' ) );
		}
		check_admin_referer( 'evote_generate_keys' );

		$threshold = isset( $_POST['evote_threshold'] ) ? absint( $_POST['evote_threshold'] ) : 0;
		$shares    = isset( $_POST['evote_shares'] ) ? absint( $_POST['evote_shares'] ) : 0;

		$result = EVote_Crypto::generate_key_material( $threshold, $shares );
		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array(
					'page'       => 'evote-generator',
					'evote_error' => rawurlencode( $result->get_error_message() ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$token = wp_generate_password( 32, false, false );
		self::store_batch( $token, $result );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'evote-generator',
					'evote_batch' => $token,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Stream JSON download.
	 */
	public static function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'decentralized-evoting' ) );
		}
		check_admin_referer( 'evote_download_export' );

		$token = isset( $_GET['evote_batch'] ) ? sanitize_text_field( wp_unslash( $_GET['evote_batch'] ) ) : '';
		$type  = isset( $_GET['evote_type'] ) ? sanitize_key( wp_unslash( $_GET['evote_type'] ) ) : '';
		$index = isset( $_GET['evote_index'] ) ? absint( $_GET['evote_index'] ) : 0;

		$package = self::get_batch( $token );
		if ( ! is_array( $package ) ) {
			wp_die( esc_html__( 'Export batch expired or not found.', 'decentralized-evoting' ) );
		}

		if ( 'public' === $type ) {
			$payload = $package['public_key'];
			$filename = 'evote-public-key-' . sanitize_file_name( $package['key_id'] ) . '.json';
		} elseif ( 'share' === $type && $index > 0 ) {
			$payload = null;
			foreach ( $package['shares'] as $share ) {
				if ( (int) $share['share_index'] === $index ) {
					$payload = $share;
					break;
				}
			}
			if ( ! $payload ) {
				wp_die( esc_html__( 'Share not found.', 'decentralized-evoting' ) );
			}
			$filename = sprintf( 'evote-share-%s-%d.json', sanitize_file_name( $package['key_id'] ), $index );
		} else {
			wp_die( esc_html__( 'Invalid download type.', 'decentralized-evoting' ) );
		}

		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! $json ) {
			wp_die( esc_html__( 'Failed to encode JSON.', 'decentralized-evoting' ) );
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON binary response
		exit;
	}

	/**
	 * @param string $token Batch id.
	 * @param string $type  public|share.
	 * @param int    $index Share index.
	 * @return string
	 */
	private static function download_url( $token, $type, $index = 0 ) {
		$args = array(
			'action'      => 'evote_download_export',
			'evote_batch' => $token,
			'evote_type'  => $type,
			'_wpnonce'    => wp_create_nonce( 'evote_download_export' ),
		);
		if ( 'share' === $type ) {
			$args['evote_index'] = $index;
		}
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * @param string               $token   Token.
	 * @param array<string, mixed> $package Data.
	 */
	private static function store_batch( $token, $package ) {
		$key = self::TRANSIENT_PREFIX . get_current_user_id() . '_' . $token;
		set_transient( $key, $package, self::TRANSIENT_TTL );
	}

	/**
	 * @param string $token Token.
	 * @return array<string, mixed>|false
	 */
	private static function get_batch( $token ) {
		if ( '' === $token ) {
			return false;
		}
		$key = self::TRANSIENT_PREFIX . get_current_user_id() . '_' . $token;
		$data = get_transient( $key );
		return is_array( $data ) ? $data : false;
	}
}
