<?php
/**
 * Catalog-backed translator for RelataSoft UI strings.
 *
 * @package RelataSoft\SecureElectionSuite\I18n
 */

namespace RelataSoft\SecureElectionSuite\I18n;

defined( 'ABSPATH' ) || exit;

/**
 * Loads JSON catalogs and hooks gettext filters.
 */
class Translator {

	public const RSES_DOMAIN = 'relatasoft-secure-election-suite';

	/**
	 * Active catalog map (msgid => msgstr).
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $rses_catalog = null;

	/**
	 * Locale the catalog was loaded for.
	 *
	 * @var string|null
	 */
	private static ?string $rses_catalog_locale = null;

	/**
	 * Register hooks.
	 */
	public static function rses_register(): void {
		add_filter( 'plugin_locale', array( LocaleResolver::class, 'rses_filter_plugin_locale' ), 10, 2 );
		add_action( 'plugins_loaded', array( self::class, 'rses_boot' ), 1 );
		add_filter( 'gettext', array( self::class, 'rses_filter_gettext' ), 10, 3 );
		add_filter( 'gettext_with_context', array( self::class, 'rses_filter_gettext_context' ), 10, 4 );
		add_filter( 'ngettext', array( self::class, 'rses_filter_ngettext' ), 10, 5 );
		add_filter( 'body_class', array( self::class, 'rses_body_class' ) );
		add_filter( 'admin_body_class', array( self::class, 'rses_admin_body_class' ) );
	}

	/**
	 * Load textdomain + JSON catalog early.
	 */
	public static function rses_boot(): void {
		$locale = LocaleResolver::rses_resolve();
		self::rses_load_catalog( $locale );

		// Standard .mo / .l10n.php if present (optional companion to JSON catalogs).
		load_plugin_textdomain(
			self::RSES_DOMAIN,
			false,
			dirname( RSES_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Translate via catalog when available.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Original text.
	 * @param string $domain      Text domain.
	 */
	public static function rses_filter_gettext( string $translation, string $text, string $domain ): string {
		if ( self::RSES_DOMAIN !== $domain ) {
			return $translation;
		}

		$catalog = self::rses_get_catalog();
		if ( isset( $catalog[ $text ] ) && '' !== $catalog[ $text ] ) {
			return $catalog[ $text ];
		}

		return $translation;
	}

	/**
	 * Context-aware gettext (uses msgid only; catalogs are flat).
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Original text.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 */
	public static function rses_filter_gettext_context( string $translation, string $text, string $context, string $domain ): string {
		unset( $context );
		return self::rses_filter_gettext( $translation, $text, $domain );
	}

	/**
	 * Plural forms: catalogs store singular/plural as separate keys when present.
	 *
	 * @param string $translation Current translation.
	 * @param string $single      Singular.
	 * @param string $plural      Plural.
	 * @param int    $number      Number.
	 * @param string $domain      Domain.
	 */
	public static function rses_filter_ngettext( string $translation, string $single, string $plural, int $number, string $domain ): string {
		if ( self::RSES_DOMAIN !== $domain ) {
			return $translation;
		}

		$catalog = self::rses_get_catalog();
		$key     = ( 1 === (int) $number ) ? $single : $plural;
		if ( isset( $catalog[ $key ] ) && '' !== $catalog[ $key ] ) {
			return $catalog[ $key ];
		}

		return $translation;
	}

	/**
	 * Front-end body classes for locale / RTL.
	 *
	 * @param list<string> $classes Classes.
	 * @return list<string>
	 */
	public static function rses_body_class( array $classes ): array {
		$locale = LocaleResolver::rses_resolve();
		$classes[] = 'rses-locale-' . sanitize_html_class( strtolower( str_replace( '_', '-', $locale ) ) );
		if ( LocaleResolver::rses_is_rtl() ) {
			$classes[] = 'rses-rtl';
		}
		return $classes;
	}

	/**
	 * Admin body classes for locale / RTL.
	 *
	 * @param string $classes Classes string.
	 */
	public static function rses_admin_body_class( string $classes ): string {
		$locale   = LocaleResolver::rses_resolve();
		$classes .= ' rses-locale-' . sanitize_html_class( strtolower( str_replace( '_', '-', $locale ) ) );
		if ( LocaleResolver::rses_is_rtl() ) {
			$classes .= ' rses-rtl';
		}
		return $classes;
	}

	/**
	 * HTML dir attribute value.
	 */
	public static function rses_dir_attr(): string {
		return LocaleResolver::rses_is_rtl() ? 'rtl' : 'ltr';
	}

	/**
	 * lang + dir attributes for RelataSoft UI roots.
	 */
	public static function rses_html_attrs(): string {
		$locale = LocaleResolver::rses_resolve();
		$lang   = str_replace( '_', '-', $locale );
		return 'lang="' . esc_attr( $lang ) . '" dir="' . esc_attr( self::rses_dir_attr() ) . '"';
	}

	/**
	 * @return array<string,string>
	 */
	private static function rses_get_catalog(): array {
		$locale = LocaleResolver::rses_resolve();
		if ( null === self::$rses_catalog || self::$rses_catalog_locale !== $locale ) {
			self::rses_load_catalog( $locale );
		}
		return self::$rses_catalog ?? array();
	}

	/**
	 * Load JSON catalog for locale (skip English source).
	 *
	 * @param string $locale Locale code.
	 */
	private static function rses_load_catalog( string $locale ): void {
		self::$rses_catalog_locale = $locale;
		self::$rses_catalog        = array();

		if ( 'en_US' === $locale || 'en' === $locale ) {
			return;
		}

		$file = RSES_PLUGIN_DIR . 'languages/catalogs/' . $locale . '.json';
		if ( ! is_readable( $file ) ) {
			return;
		}

		$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $json ) {
			return;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return;
		}

		$catalog = array();
		foreach ( $data as $msgid => $msgstr ) {
			if ( is_string( $msgid ) && is_string( $msgstr ) && '' !== $msgstr ) {
				$catalog[ $msgid ] = $msgstr;
			}
		}
		self::$rses_catalog = $catalog;
	}
}
