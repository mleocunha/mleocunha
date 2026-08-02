<?php
/**
 * Fase 1 (in progress): Feldman VSS math acceptance.
 *
 * Usage: php tests/feldman-vss-acceptance.php
 */

define( 'ABSPATH', true );
define( 'RSES_VERSION', '1.0.30' );

require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoException.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/BigInt.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoRandom.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/PrimeGenerator.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/ShamirSecretSharing.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoSchemeRegistry.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/FeldmanVss.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/CeremonyTranscript.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/ShareVerifyService.php';
require_once dirname( __DIR__ ) . '/includes/Exports/HashService.php';

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data.
	 * @param int   $flags Flags.
	 */
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * @param int  $length Length.
	 * @param bool $special Special.
	 * @param bool $extra Extra.
	 */
	function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
		return substr( bin2hex( random_bytes( $length ) ), 0, $length );
	}
}

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
use RelataSoft\SecureElectionSuite\Crypto\CeremonyTranscript;
use RelataSoft\SecureElectionSuite\Crypto\CryptoSchemeRegistry;
use RelataSoft\SecureElectionSuite\Crypto\FeldmanVss;
use RelataSoft\SecureElectionSuite\Crypto\ShareVerifyService;
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

rses_f_report(
	'feldman generative',
	CryptoSchemeRegistry::rses_may_generate( FeldmanVss::SCHEME_ID ),
	'active scheme'
);

// Share payload + ShareVerifyService path.
$payload = FeldmanVss::rses_build_share_payload(
	array(
		'ceremony_id'            => 'cer-test',
		'key_id'                 => 1,
		'election_round_id'      => 0,
		'threshold_t'            => 3,
		'total_n'                => 5,
		'participant_id'         => 9,
		'field_prime'            => '11',
		'share_index'            => 1,
		'share_value'            => BigInt::toDecimalString( ShamirSecretSharing::evaluatePolynomial( $coeffs, gmp_init( 1 ), $q ) ),
		'public_key'             => array(
			'p' => '23',
			'q' => '11',
			'g' => '4',
			'y' => BigInt::toDecimalString( $y ),
		),
		'commitments'            => FeldmanVss::rses_commitments_to_decimal( $commitments ),
		'public_transcript_hash' => 'abc',
	)
);
$vr = ShareVerifyService::rses_verify_payload( $payload );
rses_f_report( 'ShareVerifyService valid', ! empty( $vr['ok'] ), (string) ( $vr['code'] ?? '' ) );

$payload['share_value'] = BigInt::toDecimalString( gmp_mod( gmp_add( gmp_init( $payload['share_value'] ), gmp_init( 1 ) ), $q ) );
$payload['checksum']    = FeldmanVss::rses_compute_payload_checksum( $payload );
$vr_bad = ShareVerifyService::rses_verify_payload( $payload );
rses_f_report(
	'ShareVerifyService rejects bad share',
	empty( $vr_bad['ok'] ) && ShareVerifyService::CODE_COMMITMENT_MISMATCH === ( $vr_bad['code'] ?? '' ),
	(string) ( $vr_bad['code'] ?? '' )
);

$good = FeldmanVss::rses_build_share_payload(
	array(
		'ceremony_id'            => 'cer-test',
		'key_id'                 => 1,
		'election_round_id'      => 0,
		'threshold_t'            => 3,
		'total_n'                => 5,
		'participant_id'         => 9,
		'field_prime'            => '11',
		'share_index'            => 1,
		'share_value'            => BigInt::toDecimalString( ShamirSecretSharing::evaluatePolynomial( $coeffs, gmp_init( 1 ), $q ) ),
		'public_key'             => array(
			'p' => '23',
			'q' => '11',
			'g' => '4',
			'y' => BigInt::toDecimalString( $y ),
		),
		'commitments'            => FeldmanVss::rses_commitments_to_decimal( $commitments ),
		'public_transcript_hash' => 'abc',
	)
);
try {
	ShareVerifyService::rses_validate_for_tally( $good );
	rses_f_report( 'validate_for_tally accepts Feldman', true, 'ok' );
} catch ( Throwable $e ) {
	rses_f_report( 'validate_for_tally accepts Feldman', false, $e->getMessage() );
}

$transcript = CeremonyTranscript::rses_build(
	array(
		'ceremony_id'       => 'cer-test',
		'key_id'            => 1,
		'threshold_t'       => 3,
		'participant_count' => 5,
		'public_key'        => array(
			'p'           => '23',
			'q'           => '11',
			'g'           => '4',
			'y'           => BigInt::toDecimalString( $y ),
			'keySizeBits' => 512,
		),
		'commitments'       => FeldmanVss::rses_commitments_to_decimal( $commitments ),
		'participants'      => array(
			array( 'participant_id' => 1, 'share_index' => 1 ),
		),
	)
);
$files = CeremonyTranscript::rses_public_files( $transcript );
rses_f_report( 'transcript files', isset( $files['ceremony-manifest.json'], $files['commitments.json'] ), 'public files' );

exit( $failed > 0 ? 1 : 0 );
