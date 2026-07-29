<?php
/**
 * Uninstall handler for RelataSoft Secure Election Suite.
 *
 * @package RelataSoft\SecureElectionSuite
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$rses_tables = array(
	'rses_keys',
	'rses_shares',
	'rses_elections',
	'rses_election_rounds',
	'rses_ballot_questions',
	'rses_ballot_options',
	'rses_encrypted_votes',
	'rses_encrypted_tallies',
	'rses_tally_imports',
	'rses_official_share_submissions',
	'rses_certifications',
	'rses_audit_log',
);

foreach ( $rses_tables as $rses_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$rses_table}" );
}

$rses_options = array(
	'rses_mode',
	'rses_mode_locked',
	'rses_db_version',
	'rses_settings',
	'rses_gmp_missing_notice',
	'rses_allow_full_private_export',
);

foreach ( $rses_options as $rses_option ) {
	delete_option( $rses_option );
}
