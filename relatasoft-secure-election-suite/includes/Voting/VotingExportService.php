<?php
/**
 * Voting export service.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Exports\ManifestBuilder;
use RelataSoft\SecureElectionSuite\Exports\ZipExport;
use RelataSoft\SecureElectionSuite\KeyAuthority\KeyRepository;
use RelataSoft\SecureElectionSuite\Security\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Exports voting data as ZIP or JSON.
 *
 * Large elections (many ElGamal ciphertexts) must not be held twice in PHP
 * arrays + pretty-printed JSON — that exhausts 128M hosts. Votes are streamed
 * to a temp file in compact JSON and attached to the ZIP from disk.
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
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

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

		$rses_tallies   = EncryptedTallyService::rses_get_by_round( $round_id );
		$rses_questions = ElectionRepository::rses_get_questions( $round_id );

		$rses_ballot = array();
		foreach ( $rses_questions as $rses_q ) {
			$rses_ballot[] = array(
				'question' => (array) $rses_q,
				'options'  => array_map( static fn( $o ) => (array) $o, ElectionRepository::rses_get_options( (int) $rses_q->id ) ),
			);
		}

		$rses_tallies_export = array_map(
			static function ( $t ) {
				return array(
					'question_id'     => (int) $t->question_id,
					'option_id'       => $t->option_id ? (int) $t->option_id : null,
					'aggregate_alpha' => $t->aggregate_alpha,
					'aggregate_beta'  => $t->aggregate_beta,
					'ballot_count'    => (int) $t->ballot_count,
				);
			},
			$rses_tallies
		);

		$rses_audit = AuditLogger::rses_get_entries( 50 );

		$rses_manifest = ManifestBuilder::rses_build_voting_manifest(
			$election_id,
			$round_id,
			array(
				'ballot_count' => EncryptedVoteRepository::rses_count_distinct_voters( $round_id ),
			)
		);

		$rses_votes_path = wp_tempnam( 'rses-encrypted-votes-' . $round_id . '.json' );
		if ( ! $rses_votes_path ) {
			wp_die( esc_html__( 'Failed to create temporary file.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_vote_count = EncryptedVoteRepository::rses_write_votes_json_file( $round_id, $rses_votes_path );
		if ( $rses_vote_count < 0 ) {
			unlink( $rses_votes_path );
			wp_die( esc_html__( 'Failed to write encrypted votes export.', 'relatasoft-secure-election-suite' ) );
		}

		AuditLogger::rses_log(
			'voting_export',
			'election',
			$election_id,
			array(
				'format'     => $format,
				'vote_rows'  => $rses_vote_count,
				'round_id'   => $round_id,
			)
		);

		$rses_json_flags = JSON_UNESCAPED_SLASHES;

		if ( 'json' === $format ) {
			self::rses_send_json_package(
				'election-export-' . $election_id . '.json',
				array(
					'manifest'          => $rses_manifest,
					'public_key'        => $rses_public_key,
					'election'          => (array) $rses_election,
					'round'             => (array) $rses_round,
					'ballot'            => $rses_ballot,
					'encrypted_tallies' => $rses_tallies_export,
					'audit'             => array_map( static fn( $a ) => (array) $a, $rses_audit ),
				),
				$rses_votes_path
			);
		}

		$rses_files = array(
			'manifest.json'          => wp_json_encode( $rses_manifest, $rses_json_flags ),
			'public-key.json'        => wp_json_encode( $rses_public_key, $rses_json_flags ),
			'election.json'          => wp_json_encode( (array) $rses_election, $rses_json_flags ),
			'round.json'             => wp_json_encode( (array) $rses_round, $rses_json_flags ),
			'ballot.json'            => wp_json_encode( $rses_ballot, $rses_json_flags ),
			'encrypted-votes.json'   => array( 'path' => $rses_votes_path ),
			'encrypted-tallies.json' => wp_json_encode( $rses_tallies_export, $rses_json_flags ),
			'audit.json'             => wp_json_encode( array_map( static fn( $a ) => (array) $a, $rses_audit ), $rses_json_flags ),
			'README.txt'             => __( 'RelataSoft Secure Election Suite - Voting Export Package', 'relatasoft-secure-election-suite' ),
		);

		$rses_files['checksums.json'] = wp_json_encode(
			ManifestBuilder::rses_build_checksums( $rses_files ),
			$rses_json_flags
		);

		ZipExport::rses_send_download( 'election-export-' . $election_id . '.zip', $rses_files );
	}

	/**
	 * Stream a single JSON package download without loading all votes into RAM.
	 *
	 * @param string               $filename   Download name.
	 * @param array<string,mixed>  $parts      Package parts except encrypted_votes.
	 * @param string               $votes_path Absolute path to encrypted-votes.json array body.
	 */
	private static function rses_send_json_package( string $filename, array $parts, string $votes_path ): void {
		$rses_tmp = wp_tempnam( $filename );
		if ( ! $rses_tmp ) {
			unlink( $votes_path );
			wp_die( esc_html__( 'Failed to create temporary file.', 'relatasoft-secure-election-suite' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$rses_out = fopen( $rses_tmp, 'wb' );
		if ( false === $rses_out ) {
			unlink( $rses_tmp );
			unlink( $votes_path );
			wp_die( esc_html__( 'Failed to create temporary file.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_flags = JSON_UNESCAPED_SLASHES;
		$rses_order = array( 'manifest', 'public_key', 'election', 'round', 'ballot', 'encrypted_tallies', 'audit' );

		fwrite( $rses_out, '{' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$rses_first = true;
		foreach ( $rses_order as $rses_key ) {
			if ( ! array_key_exists( $rses_key, $parts ) ) {
				continue;
			}
			if ( ! $rses_first ) {
				fwrite( $rses_out, ',' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			}
			$rses_first = false;
			fwrite( $rses_out, wp_json_encode( $rses_key, $rses_flags ) . ':' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $rses_out, (string) wp_json_encode( $parts[ $rses_key ], $rses_flags ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}

		fwrite( $rses_out, ',"encrypted_votes":' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$rses_in = fopen( $votes_path, 'rb' );
		if ( false === $rses_in ) {
			fclose( $rses_out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			unlink( $rses_tmp );
			unlink( $votes_path );
			wp_die( esc_html__( 'Failed to read encrypted votes export.', 'relatasoft-secure-election-suite' ) );
		}
		stream_copy_to_stream( $rses_in, $rses_out );
		fclose( $rses_in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		fwrite( $rses_out, '}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $rses_out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		unlink( $votes_path );

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $rses_tmp ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		readfile( $rses_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		unlink( $rses_tmp );
		exit;
	}
}
