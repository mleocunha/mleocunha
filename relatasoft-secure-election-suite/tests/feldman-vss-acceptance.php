<?php
/**
 * Fase 1 (in progress): Feldman VSS math acceptance.
 *
 * Usage: php tests/feldman-vss-acceptance.php
 */

define( 'ABSPATH', true );
define( 'RSES_VERSION', '1.0.29' );

require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoException.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/BigInt.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoRandom.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/PrimeGenerator.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/ShamirSecretSharing.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoSchemeRegistry.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/FeldmanVss.php';

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text Text.
	 * @param string $domain Domain.
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

use RelataSoft\SecureElectionSuite\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Crypto\CryptoSchemeRegistry;
use RelataSoft\SecureElectionSuite\Crypto\FeldmanVss;
use RelataSoft\SecureElectionSuite\Crypto\ShamirSecretSharing;

$failed = 0;

/**
 * @param string $name Name.
 * @param bool   $ok   Pass.
 * @param string $msg  Message.
 */
function rses_f_report( string $name, bool $ok, string $msg ): void {
	global $failed;
	echo ( $ok ? '[PASS] ' : '[FAIL] ' ) . $name . ': ' . $msg . "\n";
	if ( ! $ok ) {
		++$failed;
	}
}

if ( ! extension_loaded( 'gmp' ) ) {
	rses_f_report( 'GMP', false, 'required' );
	exit( 1 );
}

rses_f_report(
	'scheme id',
	FeldmanVss::SCHEME_ID === CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_FELDMAN_V1,
	FeldmanVss::SCHEME_ID
);

// Toy safe prime (same as baseline vectors).
$p = gmp_init( 23 );
$q = gmp_init( 11 );
$g = gmp_init( 4 );
$x = gmp_init( 3 );
$y = BigInt::modPow( $g, $x, $p );

// Fixed coefficients for deterministic check: a0=3, a1=2, a2=5 (t=3).
$coeffs = array( $x, gmp_init( 2 ), gmp_init( 5 ) );
$commitments = array();
foreach ( $coeffs as $a ) {
	$commitments[] = BigInt::modPow( $g, $a, $p );
}
rses_f_report( 'C0 = y', 0 === gmp_cmp( $commitments[0], $y ), (string) $commitments[0] );

$all_ok = true;
for ( $i = 1; $i <= 5; ++$i ) {
	$s  = ShamirSecretSharing::evaluatePolynomial( $coeffs, gmp_init( $i ), $q );
	$ok = FeldmanVss::rses_verify_share( $i, $s, $commitments, $p, $q, $g, $y );
	if ( ! $ok ) {
		$all_ok = false;
	}
}
rses_f_report( 'all 5 shares verify', $all_ok, 't=3 n=5 fixed poly' );

// Bit-flip share value must fail.
$s1 = ShamirSecretSharing::evaluatePolynomial( $coeffs, gmp_init( 1 ), $q );
$bad = gmp_mod( gmp_add( $s1, gmp_init( 1 ) ), $q );
rses_f_report(
	'corrupted share rejected',
	! FeldmanVss::rses_verify_share( 1, $bad, $commitments, $p, $q, $g, $y ),
	'value+1'
);

// Wrong index must fail.
rses_f_report(
	'wrong index rejected',
	! FeldmanVss::rses_verify_share( 2, $s1, $commitments, $p, $q, $g, $y ),
	'index 2 with value of 1'
);

// Random split via API + reconstruct.
$split = FeldmanVss::rses_split_with_commitments( $x, 3, 5, $p, $q, $g );
rses_f_report( 'C0 matches y after split', 0 === gmp_cmp( $split['commitments'][0], $y ), 'public key' );

$verify_all = true;
foreach ( $split['shares'] as $share ) {
	if ( ! FeldmanVss::rses_verify_share( $share['x'], $share['y'], $split['commitments'], $p, $q, $g, $y ) ) {
		$verify_all = false;
	}
}
rses_f_report( 'random split shares verify', $verify_all, 'API path' );

$points = array(
	$split['shares'][0],
	$split['shares'][2],
	$split['shares'][4],
);
$recon = ShamirSecretSharing::reconstructSecret( $points, $q );
rses_f_report( 'reconstruct secret', 0 === gmp_cmp( $recon, $x ), (string) $recon );

// Generation still gated.
rses_f_report(
	'feldman not generative yet',
	! CryptoSchemeRegistry::rses_may_generate( FeldmanVss::SCHEME_ID ),
	'gated until ceremony wiring'
);

exit( $failed > 0 ? 1 : 0 );
