<?php
/**
 * CLI crypto acceptance test runner.
 *
 * Usage: php tests/crypto-acceptance.php
 */

define( 'ABSPATH', true );
define( 'RSES_VERSION', '1.0.28.0' );

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
require_once dirname( __DIR__ ) . '/includes/Crypto/CryptoSelfTest.php';

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

use RelataSoft\SecureElectionSuite\Crypto\CryptoSelfTest;

$rses_results = CryptoSelfTest::runAll();
$rses_failed  = 0;

foreach ( $rses_results as $rses_test ) {
	$rses_status = $rses_test['passed'] ? 'PASS' : 'FAIL';
	echo "[{$rses_status}] {$rses_test['name']}: {$rses_test['message']}\n";
	if ( ! $rses_test['passed'] ) {
		++$rses_failed;
	}
}

exit( $rses_failed > 0 ? 1 : 0 );
