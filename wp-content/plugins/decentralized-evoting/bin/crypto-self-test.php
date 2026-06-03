<?php
/**
 * Standalone crypto self-test (run: php bin/crypto-self-test.php from plugin dir).
 *
 * @package DecentralizedEvoting
 */

define( 'EVOTE_VENDOR_AUTOLOAD', true );
define( 'EVOTE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

require_once EVOTE_PLUGIN_DIR . 'vendor/autoload.php';
require_once EVOTE_PLUGIN_DIR . 'includes/data/rfc3526-group14.php';
require_once EVOTE_PLUGIN_DIR . 'includes/class-evote-elgamal.php';
require_once EVOTE_PLUGIN_DIR . 'includes/class-evote-shamir.php';
require_once EVOTE_PLUGIN_DIR . 'includes/class-evote-json-payloads.php';
require_once EVOTE_PLUGIN_DIR . 'includes/class-evote-ballot-codes.php';
require_once EVOTE_PLUGIN_DIR . 'includes/class-evote-homomorphic.php';

// EVote_Crypto references WordPress helpers when loaded in WP; CLI uses stubs below first.
require_once EVOTE_PLUGIN_DIR . 'includes/class-evote-crypto.php';

// Minimal WP_Error stub for CLI.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $message;
		public function __construct( $code, $message ) {
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
	function is_wp_error( $v ) {
		return $v instanceof WP_Error;
	}
}

$threshold = 3;
$shares    = 5;
echo "Generating 3-of-5 key material...\n";
$material = EVote_Crypto::generate_key_material( $threshold, $shares );
if ( is_wp_error( $material ) ) {
	fwrite( STDERR, $material->get_error_message() . "\n" );
	exit( 1 );
}

$subset = array_slice( $material['shares'], 0, $threshold );
$recon  = EVote_Crypto::reconstruct_private_key( $subset );
if ( is_wp_error( $recon ) ) {
	fwrite( STDERR, $recon->get_error_message() . "\n" );
	exit( 1 );
}

$elgamal = EVote_Elgamal::from_rfc3526_group14();
$pair    = $elgamal->generate_keypair();
$vote    = $elgamal->encode_vote_integer( 42 );
$ct      = $elgamal->encrypt( $vote, $pair['public'] );
$plain   = $elgamal->decrypt( $ct['c1'], $ct['c2'], $pair['private'] );

if ( is_wp_error( $plain ) || ! $plain->equals( $vote ) ) {
	fwrite( STDERR, "ElGamal roundtrip failed\n" );
	exit( 1 );
}

echo "OK key_id=" . $material['key_id'] . " private_reconstructed=" . substr( $recon['private'], 0, 16 ) . "...\n";

// Homomorphic one-hot prototype (exponential ElGamal).
$pub_json = $material['public_key'];
$elg      = EVote_Crypto::elgamal_from_public_key( $pub_json );
if ( is_wp_error( $elg ) ) {
	fwrite( STDERR, $elg->get_error_message() . "\n" );
	exit( 1 );
}
$candidates = array(
	array( 'ballot_number' => '11' ),
	array( 'ballot_number' => '22' ),
	array( 'ballot_number' => '33' ),
);
$votes = array( '11', '22', '11' );
$ballots = array();
foreach ( $votes as $code ) {
	$b = EVote_Homomorphic::build_one_hot_ballot( $pub_json, $candidates, $code );
	if ( is_wp_error( $b ) ) {
		fwrite( STDERR, $b->get_error_message() . "\n" );
		exit( 1 );
	}
	$ballots[] = $b;
}
$export = array(
	'public_key' => $pub_json,
	'candidates' => $candidates,
	'ballots'    => $ballots,
	'running'    => array( 'homomorphic_mode' => EVote_Homomorphic::MODE_EXP_ONE_HOT ),
);
$tally = EVote_Homomorphic::tally_one_hot( $export, $recon['private'] );
if ( is_wp_error( $tally ) ) {
	fwrite( STDERR, $tally->get_error_message() . "\n" );
	exit( 1 );
}
if ( empty( $tally['verify_match'] ) ) {
	fwrite( STDERR, "Homomorphic verify_match failed\n" );
	exit( 1 );
}
$by_code = array();
foreach ( $tally['results'] as $row ) {
	$by_code[ $row['ballot_number'] ] = (int) $row['votes'];
}
if ( 2 !== ( $by_code['11'] ?? -1 ) || 1 !== ( $by_code['22'] ?? -1 ) || 0 !== ( $by_code['33'] ?? -1 ) ) {
	fwrite( STDERR, "Homomorphic counts wrong: " . json_encode( $by_code ) . "\n" );
	exit( 1 );
}
echo "OK homomorphic one-hot tally verify_match=1\n";
exit( 0 );
