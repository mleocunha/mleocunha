<?php
/**
 * CLI acceptance: deterministic mid-search resume for chunked keygen.
 *
 * Usage: php tests/keygen-checkpoint-acceptance.php
 *
 * @package RelataSoft\SecureElectionSuite
 */

declare(strict_types=1);

if ( ! extension_loaded( 'gmp' ) ) {
	fwrite( STDERR, "GMP extension required.\n" );
	exit( 1 );
}

$rses_root = dirname( __DIR__ );

// Minimal stubs so crypto classes can load without WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $rses_root . '/' );
}

require_once $rses_root . '/includes/Crypto/CryptoException.php';
require_once $rses_root . '/includes/Crypto/BigInt.php';
require_once $rses_root . '/includes/Crypto/CryptoRandom.php';
require_once $rses_root . '/includes/Crypto/DeterministicRandom.php';
require_once $rses_root . '/includes/Crypto/PrimeGenerator.php';

use RelataSoft\SecureElectionSuite\Crypto\DeterministicRandom;
use RelataSoft\SecureElectionSuite\Crypto\PrimeGenerator;

$rses_failures = 0;

/**
 * @param string $label Label.
 * @param bool   $ok    Pass.
 */
function rses_assert( string $label, bool $ok ): void {
	global $rses_failures;
	if ( $ok ) {
		echo "PASS  {$label}\n";
		return;
	}
	++$rses_failures;
	echo "FAIL  {$label}\n";
}

$rses_seed = random_bytes( 32 );
$rses_hex  = bin2hex( $rses_seed );
$rses_bits = 512;
$rses_qbits = $rses_bits - 1;

// Same seed + attempt ⇒ identical candidate across fresh RNG instances.
$rses_a = DeterministicRandom::fromHex( $rses_hex );
$rses_b = DeterministicRandom::fromHex( $rses_hex );
for ( $i = 0; $i < 20; ++$i ) {
	$rses_qa = $rses_a->oddIntegerOfBitLengthForAttempt( $i, $rses_qbits );
	$rses_qb = $rses_b->oddIntegerOfBitLengthForAttempt( $i, $rses_qbits );
	if ( 0 !== gmp_cmp( $rses_qa, $rses_qb ) ) {
		rses_assert( "attempt {$i} identical across RNG instances", false );
		break;
	}
	if ( $i === 19 ) {
		rses_assert( 'attempt stream identical across RNG instances (20 samples)', true );
	}
}

// Mid-search resume: generate 0..9 on one RNG, then resume from attempt 5 on a new RNG.
$rses_first = DeterministicRandom::fromHex( $rses_hex );
$rses_expected = array();
for ( $i = 0; $i < 10; ++$i ) {
	$rses_expected[ $i ] = $rses_first->oddIntegerOfBitLengthForAttempt( $i, $rses_qbits );
}

$rses_resume = DeterministicRandom::fromHex( $rses_hex );
$rses_match  = true;
for ( $i = 5; $i < 10; ++$i ) {
	$rses_got = $rses_resume->oddIntegerOfBitLengthForAttempt( $i, $rses_qbits );
	if ( 0 !== gmp_cmp( $rses_got, $rses_expected[ $i ] ) ) {
		$rses_match = false;
		break;
	}
}
rses_assert( 'resume from attempt 5 matches original stream', $rses_match );

// Different attempts must not collide trivially (very weak uniqueness check).
$rses_uniq = array();
$rses_rng  = DeterministicRandom::fromHex( $rses_hex );
for ( $i = 0; $i < 30; ++$i ) {
	$rses_uniq[] = gmp_strval( $rses_rng->oddIntegerOfBitLengthForAttempt( $i, $rses_qbits ), 16 );
}
rses_assert( '30 consecutive attempts produce distinct candidates', count( $rses_uniq ) === count( array_unique( $rses_uniq ) ) );

// Smoke: trySafePrimeFromQ rejects even composites quickly and accepts a known tiny safe prime.
// p=23, q=11 (23 = 2*11+1). Not used for production key sizes.
$rses_tiny = PrimeGenerator::trySafePrimeFromQ( gmp_init( 11 ), 5 );
rses_assert( 'trySafePrimeFromQ accepts q=11 for 5-bit safe prime p=23', null !== $rses_tiny && 0 === gmp_cmp( $rses_tiny[0], gmp_init( 23 ) ) );

$rses_bad = PrimeGenerator::trySafePrimeFromQ( gmp_init( 9 ), 5 );
rses_assert( 'trySafePrimeFromQ rejects composite q=9', null === $rses_bad );

if ( $rses_failures > 0 ) {
	fwrite( STDERR, "\n{$rses_failures} failure(s).\n" );
	exit( 1 );
}

echo "\nAll keygen checkpoint checks passed.\n";
exit( 0 );
