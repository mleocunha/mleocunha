<?php
/**
 * Locale resolution for inclusive UI languages.
 *
 * Priority: browser Accept-Language → WordPress user locale → site locale.
 *
 * @package RelataSoft\SecureElectionSuite\I18n
 */

namespace RelataSoft\SecureElectionSuite\I18n;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the active RelataSoft UI locale.
 */
class LocaleResolver {

	/**
	 * Supported UI locales (WordPress-style codes).
	 *
	 * @var list<string>
	 */
	public const RSES_SUPPORTED = array(
		'en_US',
		'pt_BR',
		'pt_PT',
		'fr_FR',
		'es_ES',
		'de_DE',
		'nl_NL',
		'ru_RU',
		'zh_CN',
		'ar',
		'he_IL',
		'ca',
	);

	/**
	 * Language → preferred locale when only a language tag is available.
	 *
	 * @var array<string,string>
	 */
	private const RSES_LANG_DEFAULTS = array(
		'en' => 'en_US',
		'pt' => 'pt_BR',
		'fr' => 'fr_FR',
		'es' => 'es_ES',
		'de' => 'de_DE',
		'nl' => 'nl_NL',
		'ru' => 'ru_RU',
		'zh' => 'zh_CN',
		'ar' => 'ar',
		'he' => 'he_IL',
		'iw' => 'he_IL',
		'ca' => 'ca',
	);

	/**
	 * Cached resolved locale for this request.
	 *
	 * @var string|null
	 */
	private static ?string $rses_resolved = null;

	/**
	 * Resolve UI locale once per request.
	 */
	public static function rses_resolve(): string {
		if ( null !== self::$rses_resolved ) {
			return self::$rses_resolved;
		}

		$rses_locale = self::rses_from_browser();
		if ( ! $rses_locale ) {
			$rses_locale = self::rses_from_user();
		}
		if ( ! $rses_locale ) {
			$rses_locale = self::rses_from_site();
		}
		if ( ! $rses_locale ) {
			$rses_locale = 'en_US';
		}

		/**
		 * Filter the resolved RelataSoft UI locale.
		 *
		 * @param string $rses_locale Resolved locale.
		 */
		self::$rses_resolved = (string) apply_filters( 'rses_ui_locale', $rses_locale );
		return self::$rses_resolved;
	}

	/**
	 * Whether the resolved locale is right-to-left.
	 */
	public static function rses_is_rtl(): bool {
		return in_array( self::rses_resolve(), array( 'ar', 'he_IL' ), true );
	}

	/**
	 * Map a WordPress / BCP47 locale onto a supported RelataSoft locale.
	 *
	 * @param string $locale Candidate locale (e.g. pt-BR, pt_BR, pt).
	 * @return string|null Supported locale or null.
	 */
	public static function rses_match_supported( string $locale ): ?string {
		$locale = str_replace( '-', '_', trim( $locale ) );
		if ( '' === $locale ) {
			return null;
		}

		foreach ( self::RSES_SUPPORTED as $supported ) {
			if ( strcasecmp( $supported, $locale ) === 0 ) {
				return $supported;
			}
		}

		$lang = strtolower( strtok( $locale, '_' ) );
		if ( isset( self::RSES_LANG_DEFAULTS[ $lang ] ) ) {
			return self::RSES_LANG_DEFAULTS[ $lang ];
		}

		foreach ( self::RSES_SUPPORTED as $supported ) {
			$supported_lang = strtolower( strtok( $supported, '_' ) );
			if ( $supported_lang === $lang ) {
				return $supported;
			}
		}

		return null;
	}

	/**
	 * Filter `plugin_locale` for this plugin's text domain.
	 *
	 * @param string $locale WordPress locale.
	 * @param string $domain Text domain.
	 */
	public static function rses_filter_plugin_locale( string $locale, string $domain ): string {
		if ( 'relatasoft-secure-election-suite' !== $domain ) {
			return $locale;
		}
		return self::rses_resolve();
	}

	/**
	 * Prefer browser Accept-Language when it matches a supported locale.
	 */
	private static function rses_from_browser(): ?string {
		if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			return null;
		}

		$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );
		foreach ( self::rses_parse_accept_language( $header ) as $tag ) {
			$matched = self::rses_match_supported( $tag );
			if ( $matched && 'en_US' !== $matched ) {
				return $matched;
			}
			if ( 'en_US' === $matched ) {
				return 'en_US';
			}
		}

		return null;
	}

	/**
	 * WordPress user profile language (logged-in).
	 */
	private static function rses_from_user(): ?string {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return null;
		}

		$user_locale = function_exists( 'get_user_locale' ) ? get_user_locale() : '';
		return self::rses_match_supported( (string) $user_locale );
	}

	/**
	 * Site / blog language.
	 */
	private static function rses_from_site(): ?string {
		$site = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		return self::rses_match_supported( (string) $site );
	}

	/**
	 * Parse Accept-Language into ordered language tags (highest q first).
	 *
	 * @param string $header Raw header.
	 * @return list<string>
	 */
	private static function rses_parse_accept_language( string $header ): array {
		$parts = array_filter( array_map( 'trim', explode( ',', $header ) ) );
		$scored = array();

		foreach ( $parts as $part ) {
			$q    = 1.0;
			$tag  = $part;
			if ( str_contains( $part, ';' ) ) {
				list( $tag, $params ) = array_map( 'trim', explode( ';', $part, 2 ) );
				if ( preg_match( '/q\s*=\s*([0-9.]+)/i', $params, $m ) ) {
					$q = (float) $m[1];
				}
			}
			$tag = str_replace( '-', '_', $tag );
			if ( '' === $tag || '*' === $tag ) {
				continue;
			}
			$scored[] = array(
				'tag' => $tag,
				'q'   => $q,
			);
		}

		usort(
			$scored,
			static function ( array $a, array $b ): int {
				return $b['q'] <=> $a['q'];
			}
		);

		return array_values( array_unique( array_column( $scored, 'tag' ) ) );
	}
}
