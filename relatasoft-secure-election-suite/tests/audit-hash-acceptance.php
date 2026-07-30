<?php
/**
 * CLI test: audit hash stable across PHP int vs MySQL string types.
 *
 * Usage: php tests/audit-hash-acceptance.php
 */

define( 'ABSPATH', true );

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Minimal wp_json_encode stub.
	 *
	 * @param mixed $data Data.
	 * @param int   $options Options.
	 * @return string|false
	 */
	function wp_json_encode( $data, int $options = 0 ) {
		return json_encode( $data, $options ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

require_once dirname( __DIR__ ) . '/includes/Exports/HashService.php';

use RelataSoft\SecureElectionSuite\Exports\HashService;

$rses_write = array(
	'actor_user_id' => 1,
	'action'        => 'mode_set',
	'object_type'   => 'mode',
	'object_id'     => null,
	'previous_hash' => null,
	'payload_json'  => '{"mode":"voting"}',
	'created_at'    => '2026-07-19 15:10:14',
);

$rses_from_db = array(
	'actor_user_id' => '1',
	'action'        => 'mode_set',
	'object_type'   => 'mode',
	'object_id'     => null,
	'previous_hash' => null,
	'payload_json'  => '{"mode":"voting"}',
	'created_at'    => '2026-07-19 15:10:14',
);

$rses_hash_write = HashService::rses_hash_audit_entry( $rses_write );
$rses_hash_db    = HashService::rses_hash_audit_entry( $rses_from_db );

if ( ! hash_equals( $rses_hash_write, $rses_hash_db ) ) {
	echo "FAIL: write hash !== db-read hash\n";
	echo "write: {$rses_hash_write}\n";
	echo "db:    {$rses_hash_db}\n";
	exit( 1 );
}

$rses_with_object = array(
	'actor_user_id' => 1,
	'action'        => 'election_create',
	'object_type'   => 'election',
	'object_id'     => 1,
	'previous_hash' => $rses_hash_write,
	'payload_json'  => '{}',
	'created_at'    => '2026-07-19 15:13:44',
);

$rses_with_object_db = array(
	'actor_user_id' => '1',
	'action'        => 'election_create',
	'object_type'   => 'election',
	'object_id'     => '1',
	'previous_hash' => $rses_hash_write,
	'payload_json'  => '{}',
	'created_at'    => '2026-07-19 15:13:44',
);

$rses_h1 = HashService::rses_hash_audit_entry( $rses_with_object );
$rses_h2 = HashService::rses_hash_audit_entry( $rses_with_object_db );

if ( ! hash_equals( $rses_h1, $rses_h2 ) ) {
	echo "FAIL: object_id int/string mismatch\n";
	exit( 1 );
}

echo "PASS: audit hash canonicalization stable across MySQL string types\n";
exit( 0 );
