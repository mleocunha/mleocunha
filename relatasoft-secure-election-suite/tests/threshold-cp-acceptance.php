<?php
/**
 * Fase 2: threshold partial decrypt + Chaum–Pedersen acceptance.
 *
 * Usage: php tests/threshold-cp-acceptance.php
 */

define( 'ABSPATH', true );
define( 'RSES_VERSION', '1.0.29.1' );

require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoException.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/BigInt.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoRandom.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/PrimeGenerator.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/ElGamalCiphertext.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/ElGamalKeyPair.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/ElGamal.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoEncoding.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/HomomorphicTally.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/Polynomial.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoSchemeRegistry.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/FeldmanVss.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/ChaumPedersen.php';
require_once dirname( __DIR__ ) . '/includes/Crypto/ThresholdPartialDecrypt.php';

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

use RelataSoft\SecureElectionSuite\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Crypto\CryptoSchemeRegistry;
use RelataSoft\SecureElectionSuite\Crypto\ElGamal;
use RelataSoft\SecureElectionSuite\Crypto\FeldmanVss;
use RelataSoft\SecureElectionSuite\Crypto\HomomorphicTally;
use RelataSoft\SecureElectionSuite\Crypto\ThresholdPartialDecrypt;

$failed = 0;

/**
 * @param string $name Name.
 * @param bool   $ok   Pass.
 * @param string $msg  Message.
 */
function rses_t_report( string $name, bool $ok, string $msg ): void {
	global $failed;
	echo ( $ok ? '[PASS] ' : '[FAIL] ' ) . $name . ': ' . $msg . "\n";
	if ( ! $ok ) {
		++$failed;
	}
}

if ( ! extension_loaded( 'gmp' ) ) {
	rses_t_report( 'GMP', false, 'required' );
	exit( 1 );
}

rses_t_report(
	'active generation',
	CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_THRESHOLD_CP_V1 === CryptoSchemeRegistry::rses_active_generation_scheme(),
	CryptoSchemeRegistry::rses_active_generation_scheme()
);

try {
	$keypair = ElGamal::generateKeyPair( 512 );
	$pub     = $keypair->getPublicGmp();
	$x       = $keypair->getPrivateGmp();

	$split = FeldmanVss::rses_split_with_commitments( $x, 3, 5, $pub['p'], $pub['q'], $pub['g'] );

	$votes = array( 1, 1, 1, 0, 0 );
	$cts   = array();
	foreach ( $votes as $vote ) {
		$cts[] = HomomorphicTally::encryptCount( $vote, $pub['p'], $pub['q'], $pub['g'], $pub['y'] );
	}
	$agg = HomomorphicTally::aggregateCounts( $cts, $pub['p'] );

	$indices = array( 0, 2, 4 ); // share array offsets → indices 1,3,5
	$partials = array();
	foreach ( $indices as $off ) {
		$share = $split['shares'][ $off ];
		$partials[] = ThresholdPartialDecrypt::rses_partial_for_ciphertext(
			$agg,
			(int) $share['x'],
			$share['y'],
			$split['commitments'],
			$pub['p'],
			$pub['q'],
			$pub['g']
		);
	}

	$all_proofs = true;
	foreach ( $partials as $partial ) {
		if ( ! ThresholdPartialDecrypt::rses_verify_partial( $partial, $agg, $split['commitments'], $pub['p'], $pub['q'], $pub['g'] ) ) {
			$all_proofs = false;
		}
	}
	rses_t_report( 'all Chaum–Pedersen proofs', $all_proofs, 't=3' );

	// Corrupt one proof.
	$bad = $partials[0];
	$bad['delta'] = BigInt::toDecimalString( gmp_mod( gmp_add( BigInt::fromDecimalString( $bad['delta'] ), gmp_init( 1 ) ), $pub['p'] ) );
	rses_t_report(
		'corrupt partial rejected',
		! ThresholdPartialDecrypt::rses_verify_partial( $bad, $agg, $split['commitments'], $pub['p'], $pub['q'], $pub['g'] ),
		'delta+1'
	);

	$alpha_x = ThresholdPartialDecrypt::rses_combine_partials( $partials, $pub['p'], $pub['q'] );
	$count   = ThresholdPartialDecrypt::rses_decrypt_and_decode( $agg, $alpha_x, $pub['p'], $pub['g'], 5 );
	rses_t_report( 'threshold decrypt count', 3 === $count, (string) $count );

	// Build contribution package for one share + one synthetic tally row.
	$payload = FeldmanVss::rses_build_share_payload(
		array(
			'ceremony_id'       => 'cer-t',
			'key_id'            => 1,
			'election_round_id' => 1,
			'threshold_t'       => 3,
			'total_n'           => 5,
			'participant_id'    => 9,
			'field_prime'       => BigInt::toDecimalString( $pub['q'] ),
			'share_index'       => $split['shares'][0]['x'],
			'share_value'       => BigInt::toDecimalString( $split['shares'][0]['y'] ),
			'public_key'        => array(
				'p' => BigInt::toDecimalString( $pub['p'] ),
				'q' => BigInt::toDecimalString( $pub['q'] ),
				'g' => BigInt::toDecimalString( $pub['g'] ),
				'y' => BigInt::toDecimalString( $pub['y'] ),
			),
			'commitments'       => FeldmanVss::rses_commitments_to_decimal( $split['commitments'] ),
			'public_transcript_hash' => 'x',
		)
	);
	$payload['scheme_id'] = ThresholdPartialDecrypt::SCHEME_ID;
	$payload['checksum']  = FeldmanVss::rses_compute_payload_checksum( $payload );

	$tallies = array(
		array(
			'question_id'      => 1,
			'option_id'        => 2,
			'aggregate_alpha'  => BigInt::toDecimalString( $agg->getAlpha() ),
			'aggregate_beta'   => BigInt::toDecimalString( $agg->getBeta() ),
			'ballot_count'     => 5,
		),
	);

	$contrib = ThresholdPartialDecrypt::rses_build_contribution( $payload, $tallies );
	ThresholdPartialDecrypt::rses_validate_contribution( $contrib );
	rses_t_report( 'contribution package', ThresholdPartialDecrypt::SCHEME_ID === $contrib['scheme_id'], 'validated' );

	unset( $x );
} catch ( Throwable $e ) {
	rses_t_report( 'exception', false, $e->getMessage() );
}

exit( $failed > 0 ? 1 : 0 );
