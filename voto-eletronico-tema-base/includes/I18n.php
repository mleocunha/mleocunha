<?php
/**
 * Theme i18n (pt_BR default, catalog-backed).
 *
 * @package VotoEletronicoTemaBase
 */

namespace VotoEletronicoTemaBase;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight JSON catalog translator for theme strings.
 */
final class I18n {

	public const DOMAIN = 'voto-eletronico-tema-base';

	/**
	 * @var array<string,string>|null
	 */
	private static ?array $catalog = null;

	/**
	 * @var string|null
	 */
	private static ?string $catalog_locale = null;

	/**
	 * Hook registration.
	 */
	public static function init(): void {
		add_action( 'after_setup_theme', array( self::class, 'boot' ), 1 );
		add_filter( 'gettext', array( self::class, 'filter_gettext' ), 10, 3 );
		add_filter( 'gettext_with_context', array( self::class, 'filter_gettext_context' ), 10, 4 );
	}

	/**
	 * Boot textdomain + catalog.
	 */
	public static function boot(): void {
		load_theme_textdomain( self::DOMAIN, VETB_DIR . '/languages' );
		self::load_catalog( self::locale() );
	}

	/**
	 * Active locale (pt_BR when site locale is English/empty).
	 */
	public static function locale(): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$locale = is_string( $locale ) ? $locale : 'pt_BR';

		if ( '' === $locale || 'en_US' === $locale || 'en' === $locale ) {
			return 'pt_BR';
		}

		return $locale;
	}

	/**
	 * Whether current theme locale is RTL.
	 */
	public static function is_rtl(): bool {
		$locale = strtolower( self::locale() );
		return (bool) preg_match( '/^(ar|he|fa|ur)/', $locale );
	}

	/**
	 * Translate a theme msgid via catalog / gettext.
	 */
	public static function translate( string $text ): string {
		$catalog = self::get_catalog();
		if ( isset( $catalog[ $text ] ) && '' !== $catalog[ $text ] ) {
			return $catalog[ $text ];
		}

		return translate( $text, self::DOMAIN ); // phpcs:ignore WordPress.WP.I18n.LowLevelFunctions
	}

	/**
	 * Echo escaped translation.
	 */
	public static function e( string $text ): void {
		echo esc_html( self::translate( $text ) );
	}

	/**
	 * gettext filter for theme domain.
	 */
	public static function filter_gettext( string $translation, string $text, string $domain ): string {
		if ( self::DOMAIN !== $domain ) {
			return $translation;
		}

		$catalog = self::get_catalog();
		if ( isset( $catalog[ $text ] ) && '' !== $catalog[ $text ] ) {
			return $catalog[ $text ];
		}

		return $translation;
	}

	/**
	 * Context gettext filter.
	 */
	public static function filter_gettext_context( string $translation, string $text, string $context, string $domain ): string {
		unset( $context );
		return self::filter_gettext( $translation, $text, $domain );
	}

	/**
	 * @return array<string,string>
	 */
	private static function get_catalog(): array {
		$locale = self::locale();
		if ( null === self::$catalog || self::$catalog_locale !== $locale ) {
			self::load_catalog( $locale );
		}
		return self::$catalog ?? array();
	}

	/**
	 * Load JSON catalog.
	 */
	private static function load_catalog( string $locale ): void {
		self::$catalog_locale = $locale;
		self::$catalog        = array();

		$candidates = array( $locale );
		if ( str_contains( $locale, '_' ) ) {
			$candidates[] = strtok( $locale, '_' );
		}
		if ( 'pt_BR' !== $locale ) {
			$candidates[] = 'pt_BR';
		}

		foreach ( array_unique( $candidates ) as $code ) {
			$file = VETB_DIR . '/languages/catalogs/' . $code . '.json';
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $json ) {
				continue;
			}
			$data = json_decode( $json, true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$catalog = array();
			foreach ( $data as $msgid => $msgstr ) {
				if ( is_string( $msgid ) && is_string( $msgstr ) && '' !== $msgstr ) {
					$catalog[ $msgid ] = $msgstr;
				}
			}
			self::$catalog = $catalog;
			return;
		}
	}
}
