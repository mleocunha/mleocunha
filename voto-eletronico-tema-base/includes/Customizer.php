<?php
/**
 * Optional theme Customizer extras (login remains owned by the plugin).
 *
 * @package VotoEletronicoTemaBase
 */

namespace VotoEletronicoTemaBase;

defined( 'ABSPATH' ) || exit;

/**
 * Light Customizer panel — does not replace plugin login/logo settings.
 */
final class Customizer {

	public const SECTION = 'vetb_electoral';

	/**
	 * Hook registration.
	 */
	public static function init(): void {
		add_action( 'customize_register', array( self::class, 'register' ) );
		add_action( 'wp_head', array( self::class, 'output_css_vars' ), 5 );
	}

	/**
	 * Register optional Appearance → Customize settings.
	 *
	 * @param \WP_Customize_Manager $wp_customize Manager.
	 */
	public static function register( \WP_Customize_Manager $wp_customize ): void {
		$wp_customize->add_section(
			self::SECTION,
			array(
				'title'       => I18n::translate( 'Electoral front (optional)' ),
				'description' => I18n::translate( 'Optional theme extras. Login screen and white-label logos stay under Election Suite (plugin).' ),
				'priority'    => 35,
			)
		);

		$wp_customize->add_setting(
			'vetb_show_topbar_label',
			array(
				'default'           => true,
				'sanitize_callback' => array( self::class, 'sanitize_checkbox' ),
			)
		);
		$wp_customize->add_control(
			'vetb_show_topbar_label',
			array(
				'label'   => I18n::translate( 'Show “Electronic voting” label in the top bar' ),
				'section' => self::SECTION,
				'type'    => 'checkbox',
			)
		);

		$wp_customize->add_setting(
			'vetb_show_footer_note',
			array(
				'default'           => true,
				'sanitize_callback' => array( self::class, 'sanitize_checkbox' ),
			)
		);
		$wp_customize->add_control(
			'vetb_show_footer_note',
			array(
				'label'   => I18n::translate( 'Show footer note (hidden on the booth)' ),
				'section' => self::SECTION,
				'type'    => 'checkbox',
			)
		);

		$wp_customize->add_setting(
			'vetb_formal_thank_you',
			array(
				'default'           => true,
				'sanitize_callback' => array( self::class, 'sanitize_checkbox' ),
			)
		);
		$wp_customize->add_control(
			'vetb_formal_thank_you',
			array(
				'label'       => I18n::translate( 'Use vertical lockup on thank-you (official tone)' ),
				'description' => I18n::translate( 'Pinwheel centered above name and slogan — for official / printable tone.' ),
				'section'     => self::SECTION,
				'type'        => 'checkbox',
			)
		);

		$wp_customize->add_setting(
			'vetb_accent_color',
			array(
				'default'           => '#ffb800',
				'sanitize_callback' => 'sanitize_hex_color',
			)
		);
		$wp_customize->add_control(
			new \WP_Customize_Color_Control(
				$wp_customize,
				'vetb_accent_color',
				array(
					'label'   => I18n::translate( 'Accent color (optional)' ),
					'section' => self::SECTION,
				)
			)
		);

		$wp_customize->add_setting(
			'vetb_waiting_message',
			array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		$wp_customize->add_control(
			'vetb_waiting_message',
			array(
				'label'       => I18n::translate( 'Custom waiting message' ),
				'description' => I18n::translate( 'Shown on empty / waiting states with the animated pinwheel. Leave blank for the default.' ),
				'section'     => self::SECTION,
				'type'        => 'text',
			)
		);
	}

	/**
	 * Checkbox sanitizer.
	 *
	 * @param mixed $value Raw.
	 */
	public static function sanitize_checkbox( $value ): bool {
		return (bool) $value;
	}

	/**
	 * Whether the top bar label is enabled.
	 */
	public static function show_topbar_label(): bool {
		return (bool) get_theme_mod( 'vetb_show_topbar_label', true );
	}

	/**
	 * Whether the footer note is enabled.
	 */
	public static function show_footer_note(): bool {
		return (bool) get_theme_mod( 'vetb_show_footer_note', true );
	}

	/**
	 * Whether thank-you uses the vertical (formal) lockup.
	 */
	public static function formal_thank_you(): bool {
		return (bool) get_theme_mod( 'vetb_formal_thank_you', true );
	}

	/**
	 * Optional waiting message override.
	 */
	public static function waiting_message(): string {
		$custom = trim( (string) get_theme_mod( 'vetb_waiting_message', '' ) );
		return $custom;
	}

	/**
	 * Inject optional accent CSS variable.
	 */
	public static function output_css_vars(): void {
		$accent = sanitize_hex_color( (string) get_theme_mod( 'vetb_accent_color', '#ffb800' ) );
		if ( ! is_string( $accent ) || '' === $accent || '#ffb800' === strtolower( $accent ) ) {
			return;
		}
		echo '<style id="vetb-customizer-vars">:root{--vetb-gold:' . esc_attr( $accent ) . ';}</style>' . "\n";
	}
}
