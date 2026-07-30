<?php
/**
 * Voting export service.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Exports\JsonExport;
use RelataSoft\SecureElectionSuite\Exports\ManifestBuilder;
use RelataSoft\SecureElectionSuite\Exports\ZipExport;
use RelataSoft\SecureElectionSuite\KeyAuthority\KeyRepository;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Exports voting data as ZIP or JSON.
 */
class VotingExportService {

	/**
	 * Export election data.
	 *
	 * @param int    $election_id Election ID.
	 * @param int    $round_id    Round ID.
	 * @param string $format      zip or json.
	 */
	public static function rses_export( int $election_id, int $round_id, string $format = 'zip' ): void {
		$rses_election = ElectionRepository::rses_get( $election_id );
		$rses_round    = ElectionRepository::rses_get_round( $round_id );

		if ( ! $rses_election || ! $rses_round ) {
			wp_die( esc_html__( 'Election not found.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_key = KeyRepository::rses_get( (int) $rses_round->key_id );

		$rses_public_key = $rses_key ? array(
			'p' => $rses_key->public_p,
			'q' => $rses_key->public_q,
			'g' => $rses_key->public_g,
			'y' => $rses_key->public_y,
		) : array();

		$rses_votes   = EncryptedVoteRepository::rses_get_by_round( $round_id );
		$rses_tallies = EncryptedTallyService::rses_get_by_round( $round_id );
		$rses_questions = ElectionRepository::rses_get_questions( $round_id );

		$rses_ballot = array();
		foreach ( $rses_questions as $rses_q ) {
			$rses_ballot[] = array(
				'question' => (array) $rses_q,
				'options'  => array_map( static fn( $o ) => (array) $o, ElectionRepository::rses_get_options( (int) $rses_q->id ) ),
			);
		}

		$rses_votes_export = array_map( static function ( $v ) {
			return array(
				'id'               => (int) $v->id,
				'question_id'      => (int) $v->question_id,
				'option_id'        => $v->option_id ? (int) $v->option_id : null,
				'ciphertext_alpha' => $v->ciphertext_alpha,
				'ciphertext_beta'  => $v->ciphertext_beta,
				'vote_hash'        => $v->vote_hash,
				'cast_at'          => $v->cast_at,
			);
		}, $rses_votes );

		$rses_tallies_export = array_map( static function ( $t ) {
			return array(
				'question_id'     => (int) $t->question_id,
				'option_id'       => $t->option_id ? (int) $t->option_id : null,
				'aggregate_alpha' => $t->aggregate_alpha,
				'aggregate_beta'  => $t->aggregate_beta,
				'ballot_count'    => (int) $t->ballot_count,
			);
		}, $rses_tallies );

		$rses_audit = AuditLogger::rses_get_entries( 50 );

		$rses_package = array(
			'manifest'         => ManifestBuilder::rses_build_voting_manifest( $election_id, $round_id, array(
				'ballot_count' => count( array_unique( array_map( static fn( $v ) => (int) $v->voter_user_id, $rses_votes ) ) ),
			) ),
			'public_key'       => $rses_public_key,
			'election'         => (array) $rses_election,
			'round'            => (array) $rses_round,
			'ballot'           => $rses_ballot,
			'encrypted_votes'  => $rses_votes_export,
			'encrypted_tallies'=> $rses_tallies_export,
			'audit'            => array_map( static fn( $a ) => (array) $a, $rses_audit ),
		);

		AuditLogger::rses_log( 'voting_export', 'election', $election_id, array( 'format' => $format ) );

		if ( 'json' === $format ) {
			JsonExport::rses_send_download( 'election-export-' . $election_id . '.json', $rses_package );
		}

		$rses_files = array(
			'manifest.json'         => wp_json_encode( $rses_package['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'public-key.json'       => wp_json_encode( $rses_public_key, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'election.json'         => wp_json_encode( (array) $rses_election, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'round.json'            => wp_json_encode( (array) $rses_round, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'ballot.json'           => wp_json_encode( $rses_ballot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'encrypted-votes.json'  => wp_json_encode( $rses_votes_export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'encrypted-tallies.json'=> wp_json_encode( $rses_tallies_export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'audit.json'            => wp_json_encode( array_map( static fn( $a ) => (array) $a, $rses_audit ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'README.txt'            => __( 'RelataSoft Secure Election Suite - Voting Export Package', 'relatasoft-secure-election-suite' ),
		);

		$rses_files['checksums.json'] = wp_json_encode( ManifestBuilder::rses_build_checksums( $rses_files ), JSON_PRETTY_PRINT );

		ZipExport::rses_send_download( 'election-export-' . $election_id . '.zip', $rses_files );
	}
}
