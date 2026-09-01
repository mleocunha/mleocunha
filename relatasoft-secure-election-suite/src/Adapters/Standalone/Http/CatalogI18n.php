<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http;

/**
 * Resolução de locale a partir de Accept-Language e catálogos JSON em languages/catalogs.
 */
final class CatalogI18n {

	/** @var list<string> */
	public const SUPPORTED = array(
		'en_US', 'pt_BR', 'pt_PT', 'fr_FR', 'es_ES', 'de_DE',
		'nl_NL', 'ru_RU', 'zh_CN', 'ar', 'he_IL', 'ca',
	);

	/** @var array<string,string> */
	private const LANG_DEFAULTS = array(
		'en' => 'en_US', 'pt' => 'pt_BR', 'fr' => 'fr_FR', 'es' => 'es_ES',
		'de' => 'de_DE', 'nl' => 'nl_NL', 'ru' => 'ru_RU', 'zh' => 'zh_CN',
		'ar' => 'ar', 'he' => 'he_IL', 'iw' => 'he_IL', 'ca' => 'ca',
	);

	/** @var array<string,string> */
	private array $catalog = array();

	private string $locale = 'pt_BR';

	public function __construct( string $catalogsDir, string $locale ) {
		$this->locale = $this->normalizeLocale( $locale );
		$path = rtrim( $catalogsDir, '/\\' ) . '/' . $this->locale . '.json';
		if ( ! is_readable( $path ) && 'en_US' !== $this->locale ) {
			$path = rtrim( $catalogsDir, '/\\' ) . '/en_US.json';
		}
		if ( is_readable( $path ) ) {
			$data = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $data ) ) {
				foreach ( $data as $k => $v ) {
					if ( is_string( $k ) && is_string( $v ) ) {
						$this->catalog[ $k ] = $v;
					}
				}
			}
		}
	}

	public function locale(): string {
		return $this->locale;
	}

	public function dir(): string {
		return in_array( $this->locale, array( 'ar', 'he_IL' ), true ) ? 'rtl' : 'ltr';
	}

	public function t( string $msgid ): string {
		return $this->catalog[ $msgid ] ?? $msgid;
	}

	/**
	 * Palavra que o administrador deve digitar para acções destrutivas.
	 * Case-sensitive; msgid inglês «Confirm»; pt_BR → «Confirmo».
	 */
	public function destructiveConfirmWord(): string {
		$word = $this->t( 'Confirm' );
		return '' !== $word ? $word : 'Confirm';
	}

	/**
	 * Comparação exacta (case-sensitive) após trim.
	 */
	public function matchesDestructiveConfirm( string $typed ): bool {
		$expected = $this->destructiveConfirmWord();
		$typed    = trim( $typed );
		return '' !== $expected && '' !== $typed && $expected === $typed;
	}

	public static function fromAcceptLanguage( ?string $header, string $fallback = 'pt_BR' ): string {
		if ( null === $header || '' === trim( $header ) ) {
			return self::normalizeLocaleStatic( $fallback );
		}
		$parts = explode( ',', $header );
		foreach ( $parts as $part ) {
			$tag = strtolower( trim( explode( ';', $part )[0] ?? '' ) );
			$tag = str_replace( '-', '_', $tag );
			$matched = self::matchSupported( $tag );
			if ( null !== $matched ) {
				return $matched;
			}
		}
		return self::normalizeLocaleStatic( $fallback );
	}

	private function normalizeLocale( string $locale ): string {
		return self::normalizeLocaleStatic( $locale );
	}

	private static function normalizeLocaleStatic( string $locale ): string {
		$locale = str_replace( '-', '_', trim( $locale ) );
		$matched = self::matchSupported( $locale );
		return $matched ?? 'pt_BR';
	}

	private static function matchSupported( string $tag ): ?string {
		foreach ( self::SUPPORTED as $code ) {
			if ( strcasecmp( $code, $tag ) === 0 ) {
				return $code;
			}
		}
		$lang = strtolower( (string) strtok( $tag, '_' ) );
		if ( isset( self::LANG_DEFAULTS[ $lang ] ) ) {
			return self::LANG_DEFAULTS[ $lang ];
		}
		foreach ( self::SUPPORTED as $code ) {
			if ( str_starts_with( strtolower( $code ), $lang ) ) {
				return $code;
			}
		}
		return null;
	}
}
