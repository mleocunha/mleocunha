<?php
/**
 * Fase 1: Feldman VSS math acceptance (clean-cut lineage).
 *
 * Usage: php tests/feldman-vss-acceptance.php
 */

define( 'ABSPATH', true );
define( 'RSES_VERSION', '1.0.28.0' );

require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoException.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/BigInt.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoRandom.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/PrimeGenerator.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/Polynomial.php';
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
use RelataSoft\SecureElectionSuite\Crypto\Polynomial;
use RelataSoft\SecureElectionSuite\Crypto\ShareVerifyService;

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

rses_f_report(
	'shamir retired from generate',
	! CryptoSchemeRegistry::rses_may_generate( CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_SHAMIR_V1 ),
	'modp-elgamal-shamir-v1'
);

rses_f_report(
	'shamir not verifiable here',
	! CryptoSchemeRegistry::rses_may_verify( CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_SHAMIR_V1 ),
	'clean cut'
);

// Toy safe prime.
$p = gmp_init( 23 );
$q = gmp_init( 11 );
$g = gmp_init( 4 );
$x = gmp_init( 3 );
$y = BigInt::modPow( $g, $x, $p );

$coeffs = array( $x, gmp_init( 2 ), gmp_init( 5 ) );
$commitments = array();
foreach ( $coeffs as $a ) {
	$commitments[] = BigInt::modPow( $g, $a, $p );
}
rses_f_report( 'C0 = y', 0 === gmp_cmp( $commitments[0], $y ), (string) $commitments[0] );

$all_ok = true;
$shares = array();
for ( $i = 1; $i <= 5; ++$i ) {
	$s  = Polynomial::rses_evaluate( $coeffs, gmp_init( $i ), $q );
	$ok = FeldmanVss::rses_verify_share( $i, $s, $commitments, $p, $q, $g, $y );
	$shares[] = array( 'x' => $i, 'y' => $s );
	if ( ! $ok ) {
		$all_ok = false;
	}
}
rses_f_report( 'all 5 shares verify', $all_ok, 't=3 n=5 fixed poly' );

$s1  = Polynomial::rses_evaluate( $coeffs, gmp_init( 1 ), $q );
$bad = gmp_mod( gmp_add( $s1, gmp_init( 1 ) ), $q );
rses_f_report(
	'corrupted share rejected',
	! FeldmanVss::rses_verify_share( 1, $bad, $commitments, $p, $q, $g, $y ),
	'value+1'
);

rses_f_report(
	'wrong index rejected',
	! FeldmanVss::rses_verify_share( 2, $s1, $commitments, $p, $q, $g, $y ),
	'index=2 with s1'
);

$recon = Polynomial::rses_reconstruct_with_threshold(
	array( $shares[0], $shares[2], $shares[4] ),
	$q,
	3
);
rses_f_report( 'reconstruct x', 0 === gmp_cmp( $recon, $x ), (string) $recon );

$payload = FeldmanVss::rses_build_share_payload(
	array(
		'ceremony_id'            => 'cer-test',
		'key_id'                 => 7,
		'election_round_id'      => 1,
		'threshold_t'            => 3,
		'total_n'                => 5,
		'participant_id'         => 42,
		'field_prime'            => BigInt::toDecimalString( $q ),
		'share_index'            => 1,
		'share_value'            => BigInt::toDecimalString( $s1 ),
		'public_key'             => array(
			'p' => BigInt::toDecimalString( $p ),
			'q' => BigInt::toDecimalString( $q ),
			'g' => BigInt::toDecimalString( $g ),
			'y' => BigInt::toDecimalString( $y ),
		),
		'commitments'            => FeldmanVss::rses_commitments_to_decimal( $commitments ),
		'public_transcript_hash' => 'abc',
	)
);

try {
	FeldmanVss::validateSharePayload( $payload );
	rses_f_report( 'payload validate', true, 'ok' );
} catch ( Exception $e ) {
	rses_f_report( 'payload validate', false, $e->getMessage() );
}

$verify = ShareVerifyService::rses_verify_payload( $payload );
rses_f_report( 'ShareVerifyService valid', ! empty( $verify['ok'] ), (string) ( $verify['code'] ?? '' ) );

$legacy = array(
	'version' => '1.0',
	'scheme'  => 'ShamirSecretSharing',
	'checksum'=> '00',
);
$legacy_v = ShareVerifyService::rses_verify_payload( $legacy );
rses_f_report(
	'legacy Shamir rejected',
	empty( $legacy_v['ok'] ) && ShareVerifyService::CODE_SCHEME_UNSUPPORTED === ( $legacy_v['code'] ?? '' ),
	(string) ( $legacy_v['code'] ?? '' )
);

$transcript = CeremonyTranscript::rses_build(
	array(
		'scheme_id'         => FeldmanVss::SCHEME_ID,
		'ceremony_id'       => 'cer-test',
		'key_id'            => 7,
		'threshold_t'       => 3,
		'participant_count' => 5,
		'public_key'        => array(
			'p' => BigInt::toDecimalString( $p ),
			'q' => BigInt::toDecimalString( $q ),
			'g' => BigInt::toDecimalString( $g ),
			'y' => BigInt::toDecimalString( $y ),
			'keySizeBits' => 8,
		),
		'commitments'       => FeldmanVss::rses_commitments_to_decimal( $commitments ),
		'participants'      => array(
			array( 'participant_id' => 42, 'share_index' => 1 ),
		),
	)
);
$files = CeremonyTranscript::rses_public_files( $transcript );
rses_f_report(
	'transcript files',
	isset( $files['ceremony-manifest.json'], $files['commitments.json'] )
		&& ! empty( $transcript['public_transcript_hash'] ),
	(string) ( $transcript['public_transcript_hash'] ?? '' )
);

exit( $failed > 0 ? 1 : 0 );
