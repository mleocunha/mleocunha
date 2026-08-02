<?php
/**
 * Fase 0 baseline acceptance: scheme registry + fixed tiny vectors.
 *
 * Usage: php tests/baseline-scheme-acceptance.php
 *
 * Locks arithmetic of modp-elgamal-shamir-v1 against checked-in toy vectors.
 * Does not replace CryptoSelfTest (which uses fresh CSPRNG 512-bit keys).
 */

define( 'ABSPATH', true );
define( 'RSES_VERSION', '1.0.29' );

require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoException.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/BigInt.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoRandom.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/ElGamalCiphertext.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/ElGamal.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoEncoding.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/ShamirSecretSharing.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoSchemeRegistry.php';

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
use RelataSoft\SecureElectionSuite\Crypto\CryptoEncoding;
use RelataSoft\SecureElectionSuite\Crypto\CryptoSchemeRegistry;
use RelataSoft\SecureElectionSuite\Crypto\ElGamal;
use RelataSoft\SecureElectionSuite\Crypto\ElGamalCiphertext;
use RelataSoft\SecureElectionSuite\Crypto\ShamirSecretSharing;

$rses_failed = 0;

/**
 * @param string $name Name.
 * @param bool   $ok   Pass.
 * @param string $msg  Message.
 */
function rses_report( string $name, bool $ok, string $msg ): void {
	global $rses_failed;
	echo ( $ok ? '[PASS] ' : '[FAIL] ' ) . $name . ': ' . $msg . "\n";
	if ( ! $ok ) {
		++$rses_failed;
	}
}

if ( ! extension_loaded( 'gmp' ) ) {
	rses_report( 'GMP', false, 'GMP extension required' );
	exit( 1 );
}

rses_report(
	'Active generation scheme',
	CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_SHAMIR_V1 === CryptoSchemeRegistry::rses_active_generation_scheme(),
	CryptoSchemeRegistry::rses_active_generation_scheme()
);

rses_report(
	'Baseline may generate',
	CryptoSchemeRegistry::rses_may_generate( CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_SHAMIR_V1 ),
	'modp-elgamal-shamir-v1'
);

rses_report(
	'Feldman not yet generative',
	! CryptoSchemeRegistry::rses_may_generate( CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_FELDMAN_V1 ),
	'feldman gated until Fase 1'
);

$rses_path = __DIR__ . '/vectors/modp-elgamal-shamir-v1-tiny.json';
$rses_raw  = file_get_contents( $rses_path );
$rses_vec  = is_string( $rses_raw ) ? json_decode( $rses_raw, true ) : null;

rses_report( 'Vector file', is_array( $rses_vec ), $rses_path );

if ( ! is_array( $rses_vec ) ) {
	exit( 1 );
}

rses_report(
	'Vector scheme_id',
	( $rses_vec['scheme_id'] ?? '' ) === CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_SHAMIR_V1,
	(string) ( $rses_vec['scheme_id'] ?? '' )
);

$eg = $rses_vec['elgamal'];
$p  = gmp_init( (string) $eg['p'] );
$q  = gmp_init( (string) $eg['q'] );
$g  = gmp_init( (string) $eg['g'] );
$x  = gmp_init( (string) $eg['x'] );
$y  = gmp_init( (string) $eg['y'] );

rses_report( 'g^q ≡ 1', 0 === gmp_cmp( BigInt::modPow( $g, $q, $p ), gmp_init( 1 ) ), 'subgroup' );
rses_report( 'y = g^x', 0 === gmp_cmp( $y, BigInt::modPow( $g, $x, $p ) ), 'public key' );

$enc = $rses_vec['encrypt_known_r'];
$m   = gmp_init( (string) $enc['message_g_pow_count'] );
rses_report(
	'encodeCount(1)',
	0 === gmp_cmp( $m, CryptoEncoding::encodeCount( 1, $g, $p ) ),
	'g^1'
);

$ct = new ElGamalCiphertext(
	gmp_init( (string) $enc['alpha'] ),
	gmp_init( (string) $enc['beta'] )
);
$dec = ElGamal::decrypt( $ct, $p, $x );
rses_report( 'decrypt known ciphertext', 0 === gmp_cmp( $dec, $m ), 'message recovered' );

// Aggregate two votes of 1 with fixed r values (manual product, then decrypt).
$r1    = gmp_init( (string) $rses_vec['aggregate_two_votes_of_one']['r1'] );
$r2    = gmp_init( (string) $rses_vec['aggregate_two_votes_of_one']['r2'] );
$alpha = BigInt::modMul( BigInt::modPow( $g, $r1, $p ), BigInt::modPow( $g, $r2, $p ), $p );
$beta  = BigInt::modMul(
	BigInt::modMul( $m, BigInt::modPow( $y, $r1, $p ), $p ),
	BigInt::modMul( $m, BigInt::modPow( $y, $r2, $p ), $p ),
	$p
);
$agg_msg = ElGamal::decrypt( new ElGamalCiphertext( $alpha, $beta ), $p, $x );
$expect  = gmp_init( (string) $rses_vec['aggregate_two_votes_of_one']['decrypted_message'] );
rses_report( 'homomorphic aggregate decrypt', 0 === gmp_cmp( $agg_msg, $expect ), 'g^2' );

$decoded = CryptoEncoding::decodeCount( $agg_msg, $g, $p, 5 );
rses_report(
	'bounded dlog',
	2 === (int) $decoded,
	'count=' . (string) $decoded
);

$sh   = $rses_vec['shamir'];
$P    = gmp_init( (string) $sh['field_prime'] );
$secs = gmp_init( (string) $sh['secret'] );
$coef = array_map( static fn( $c ) => gmp_init( (string) $c ), $sh['coefficients'] );

foreach ( $sh['shares'] as $row ) {
	$idx = (int) $row['index'];
	$val = gmp_init( (string) $row['value'] );
	$got = ShamirSecretSharing::evaluatePolynomial( $coef, gmp_init( $idx ), $P );
	rses_report( "shamir eval i={$idx}", 0 === gmp_cmp( $got, $val ), (string) $got );
}

$points = array();
foreach ( $sh['reconstruct_indices'] as $idx ) {
	foreach ( $sh['shares'] as $row ) {
		if ( (int) $row['index'] === (int) $idx ) {
			$points[] = array(
				'x' => (int) $idx,
				'y' => gmp_init( (string) $row['value'] ),
			);
		}
	}
}
$recon = ShamirSecretSharing::reconstructSecret( $points, $P );
rses_report( 'shamir reconstruct t-of-n', 0 === gmp_cmp( $recon, $secs ), (string) $recon );

// Historical share scheme string still used in JSON builders.
rses_report(
	'historical share scheme constant',
	'ShamirSecretSharing' === ShamirSecretSharing::RSES_SHARE_SCHEME,
	ShamirSecretSharing::RSES_SHARE_SCHEME
);

exit( $rses_failed > 0 ? 1 : 0 );
