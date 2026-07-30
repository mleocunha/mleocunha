<?php
/**
 * Strip WordPress front-end chrome (menus, sidebars, widgets, chrome chrome).
 *
 * @package VotoEletronicoTemaBase
 */

namespace VotoEletronicoTemaBase;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the electoral canvas free of WP furniture.
 */
final class Chrome {

	/**
	 * Hook registration.
	 */
	public static function init(): void {
		add_action( 'widgets_init', array( self::class, 'unregister_sidebars' ), 99 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'dequeue_clutter' ), 100 );
		add_filter( 'show_admin_bar', array( self::class, 'maybe_hide_admin_bar' ) );
		add_action( 'init', array( self::class, 'remove_emoji' ) );
		add_filter( 'excerpt_more', static fn() => '…' );
	}

	/**
	 * Ensure no sidebars remain registered.
	 */
	public static function unregister_sidebars(): void {
		$sidebars = $GLOBALS['wp_registered_sidebars'] ?? array();
		if ( ! is_array( $sidebars ) ) {
			return;
		}
		foreach ( array_keys( $sidebars ) as $id ) {
			unregister_sidebar( (string) $id );
		}
	}

	/**
	 * Drop nonessential front styles/scripts.
	 */
	public static function dequeue_clutter(): void {
		wp_dequeue_style( 'wp-block-library-theme' );
		wp_dequeue_style( 'classic-theme-styles' );
		wp_dequeue_style( 'global-styles' );

		// Keep block library lightly — shortcodes may rely on basic content styles.
		wp_dequeue_script( 'wp-embed' );
	}

	/**
	 * Hide admin bar for electors / non-editors on the front.
	 *
	 * @param bool $show Current.
	 */
	public static function maybe_hide_admin_bar( bool $show ): bool {
		if ( is_admin() ) {
			return $show;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( current_user_can( 'edit_posts' ) ) {
			return $show;
		}

		return false;
	}

	/**
	 * Remove emoji scripts/styles on front.
	 */
	public static function remove_emoji(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
	}
}
