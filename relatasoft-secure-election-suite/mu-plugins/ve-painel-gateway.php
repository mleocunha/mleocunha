<?php
/**
 * Must-use helper: materialize /painel gateway + /id.php without symlinks.
 * Loaded even if the main plugin is being activated/updated.
 *
 * @package RelataSoft_Secure_Election_Suite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create ABSPATH/painel/*.php stubs and id.php as early as possible.
 */
function ve_painel_mu_ensure_gateway(): void {
	if ( ! defined( 'ABSPATH' ) ) {
		return;
	}

	$admin_slug = 'painel';
	$login_file = 'id.php';
	$admin_dir  = trailingslashit( ABSPATH ) . $admin_slug;
	$wp_admin   = trailingslashit( ABSPATH ) . 'wp-admin';

	// Login stub.
	$login_path = trailingslashit( ABSPATH ) . $login_file;
	if ( ! file_exists( $login_path ) ) {
		$stub = "<?php\n/** Voto Eletrônico — identificação */\ndefine( 'VE_LOGIN_ENTRY', true );\nrequire __DIR__ . '/wp-login.php';\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $login_path, $stub );
	}

	if ( ! is_dir( $wp_admin ) ) {
		return;
	}

	// Remove legacy symlink if present.
	if ( is_link( $admin_dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $admin_dir );
	}

	if ( ! is_dir( $admin_dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		if ( ! @mkdir( $admin_dir, 0755 ) && ! is_dir( $admin_dir ) ) {
			return;
		}
	}

	$marker = $admin_dir . '/.ve-admin-alias';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	@file_put_contents( $marker, "Voto Eletrônico gateway\n" );

	$files = glob( $wp_admin . '/*.php' ) ?: array();
	foreach ( $files as $file ) {
		$base = basename( $file );
		if ( ! preg_match( '/^[a-z0-9_-]+\.php$/i', $base ) ) {
			continue;
		}
		$target = $admin_dir . '/' . $base;
		if ( is_readable( $target ) ) {
			continue;
		}
		$php = "<?php\ndefine( 'VE_ADMIN_ENTRY', true );\nrequire dirname( __DIR__ ) . '/wp-admin/{$base}';\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $target, $php );
	}
}

ve_painel_mu_ensure_gateway();
