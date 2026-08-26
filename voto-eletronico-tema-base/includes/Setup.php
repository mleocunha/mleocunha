<?php
/**
 * Theme setup and assets.
 *
 * @package VotoEletronicoTemaBase
 */

namespace VotoEletronicoTemaBase;

defined( 'ABSPATH' ) || exit;

/**
 * Registers theme supports and front assets.
 */
final class Setup {

	/**
	 * Hook registration.
	 */
	public static function init(): void {
		add_action( 'after_setup_theme', array( self::class, 'register_supports' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
	}

	/**
	 * Theme supports — deliberately minimal (no menus/widgets).
	 */
	public static function register_supports(): void {
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support(
			'html5',
			array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
		);
		add_theme_support( 'custom-logo', array(
			'height'      => 120,
			'width'       => 480,
			'flex-height' => true,
			'flex-width'  => true,
			'header-text' => array( 'site-title', 'site-description' ),
		) );

		// No menus / sidebars on purpose.
		remove_theme_support( 'widgets-block-editor' );
	}

	/**
	 * Front-end assets.
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_style(
			'vetb-theme',
			VETB_URI . '/assets/css/theme.css',
			array(),
			VETB_VERSION
		);

		wp_enqueue_script(
			'vetb-theme',
			VETB_URI . '/assets/js/theme.js',
			array(),
			VETB_VERSION,
			true
		);

		wp_localize_script(
			'vetb-theme',
			'vetbTheme',
			array(
				'context' => Journey::current_context(),
				'i18n'    => array(
					'loading' => I18n::translate( 'Preparing the electoral workspace…' ),
				),
			)
		);
	}

	/**
	 * Body classes for journey context.
	 *
	 * @param list<string> $classes Classes.
	 * @return list<string>
	 */
	public static function body_class( array $classes ): array {
		$classes[] = 'vetb-theme';
		$classes[] = 'vetb-locale-' . sanitize_html_class( strtolower( str_replace( '_', '-', I18n::locale() ) ) );

		$context = Journey::current_context();
		if ( '' !== $context ) {
			$classes[] = 'vetb-context-' . sanitize_html_class( $context );
		}

		if ( I18n::is_rtl() ) {
			$classes[] = 'vetb-rtl';
		}

		return $classes;
	}
}
