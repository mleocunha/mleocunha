<?php
/**
 * Locale matching acceptance (no WordPress bootstrap).
 *
 * Run: php tests/locale-resolution-acceptance.php
 *
 * @package RelataSoft\SecureElectionSuite
 */

declare(strict_types=1);

// Minimal WP stubs used by LocaleResolver.
if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str Input.
	 */
	function sanitize_text_field( $str ) {
		return is_string( $str ) ? trim( strip_tags( $str ) ) : '';
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $hook Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	/**
	 * @return bool
	 */
	function is_user_logged_in() {
		return false;
	}
}
if ( ! function_exists( 'get_user_locale' ) ) {
	/**
	 * @return string
	 */
	function get_user_locale() {
		return 'de_DE';
	}
}
if ( ! function_exists( 'get_locale' ) ) {
	/**
	 * @return string
	 */
	function get_locale() {
		return 'en_US';
	}
}

require_once dirname( __DIR__ ) . '/includes/I18n/LocaleResolver.php';

use RelataSoft\SecureElectionSuite\I18n\LocaleResolver;

$failures = 0;

/**
 * @param string $label Label.
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 */
function rses_assert_same( string $label, $expected, $actual ): void {
	global $failures;
	if ( $expected !== $actual ) {
		echo "FAIL: {$label} expected " . var_export( $expected, true ) . ' got ' . var_export( $actual, true ) . PHP_EOL;
		++$failures;
	} else {
		echo "OK: {$label}" . PHP_EOL;
	}
}

rses_assert_same( 'pt-BR → pt_BR', 'pt_BR', LocaleResolver::rses_match_supported( 'pt-BR' ) );
rses_assert_same( 'pt → pt_BR', 'pt_BR', LocaleResolver::rses_match_supported( 'pt' ) );
rses_assert_same( 'fr → fr_FR', 'fr_FR', LocaleResolver::rses_match_supported( 'fr' ) );
rses_assert_same( 'zh-CN → zh_CN', 'zh_CN', LocaleResolver::rses_match_supported( 'zh-CN' ) );
rses_assert_same( 'ar → ar', 'ar', LocaleResolver::rses_match_supported( 'ar' ) );
rses_assert_same( 'he → he_IL', 'he_IL', LocaleResolver::rses_match_supported( 'he' ) );
rses_assert_same( 'ca → ca', 'ca', LocaleResolver::rses_match_supported( 'ca' ) );
rses_assert_same( 'nl_NL → nl_NL', 'nl_NL', LocaleResolver::rses_match_supported( 'nl_NL' ) );
rses_assert_same( 'ja unsupported', null, LocaleResolver::rses_match_supported( 'ja' ) );

// Browser preference wins over user/site.
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-FR,fr;q=0.9,en;q=0.8';
// Reset cache via reflection.
$ref = new ReflectionClass( LocaleResolver::class );
$prop = $ref->getProperty( 'rses_resolved' );
$prop->setAccessible( true );
$prop->setValue( null, null );
rses_assert_same( 'browser fr_FR', 'fr_FR', LocaleResolver::rses_resolve() );

$prop->setValue( null, null );
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'ja,en-US;q=0.5';
// ja unsupported → en_US from browser list
rses_assert_same( 'browser en after ja', 'en_US', LocaleResolver::rses_resolve() );

$prop->setValue( null, null );
unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
// no browser → site en_US (user not logged in in stub)
rses_assert_same( 'site fallback en_US', 'en_US', LocaleResolver::rses_resolve() );

rses_assert_same( 'rtl ar', true, in_array( 'ar', array( 'ar', 'he_IL' ), true ) );

if ( $failures > 0 ) {
	echo "FAILED {$failures}" . PHP_EOL;
	exit( 1 );
}

echo 'ALL PASS' . PHP_EOL;
exit( 0 );
