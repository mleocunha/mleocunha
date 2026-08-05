<?php
/**
 * Canonical public ceremony transcript for Feldman VSS.
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

use RelataSoft\SecureElectionSuite\Exports\HashService;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and hashes the public transcript shared by all official packages.
 */
class CeremonyTranscript {

	public const CEREMONY_STATUS_ACTIVE            = 'active';
	public const CEREMONY_STATUS_INVALID           = 'CEREMONY_INVALID';
	public const CEREMONY_REASON_SHARE_VERIFY_FAIL = 'SHARE_VERIFICATION_FAILED';

	/**
	 * Build canonical public transcript (no secrets).
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string,mixed>
	 */
	public static function rses_build( array $args ): array {
		$commitments  = array_values( (array) ( $args['commitments'] ?? array() ) );
		$participants = array_values( (array) ( $args['participants'] ?? array() ) );

		$transcript = array(
			'format_version'             => '1.0',
			'scheme_id'                  => (string) ( $args['scheme_id'] ?? FeldmanVss::SCHEME_ID ),
			'profile_id'                 => (string) ( $args['profile_id'] ?? 'rses-tse-aligned-primitives-draft' ),
			'ceremony_id'                => (string) ( $args['ceremony_id'] ?? '' ),
			'key_id'                     => (int) ( $args['key_id'] ?? 0 ),
			'key_label'                  => (string) ( $args['key_label'] ?? '' ),
			'election_round_id'          => (int) ( $args['election_round_id'] ?? 0 ),
			'threshold_t'                => (int) ( $args['threshold_t'] ?? 0 ),
			'participant_count'          => (int) ( $args['participant_count'] ?? 0 ),
			'created_at'                 => (string) ( $args['created_at'] ?? gmdate( 'c' ) ),
			'public_key'                 => array(
				'p'           => (string) ( $args['public_key']['p'] ?? '' ),
				'q'           => (string) ( $args['public_key']['q'] ?? '' ),
				'g'           => (string) ( $args['public_key']['g'] ?? '' ),
				'y'           => (string) ( $args['public_key']['y'] ?? '' ),
				'keySizeBits' => (int) ( $args['public_key']['keySizeBits'] ?? 0 ),
			),
			'commitments'                => $commitments,
			'participants'               => $participants,
			'field'                      => 'elgamal_subgroup_order_q',
			'key_generation_mode'        => 'trusted_dealer',
			'private_key_reconstruction' => 'permitted_during_tally',
			'security_generation'        => 'transitional',
		);

		$transcript['public_transcript_hash'] = self::rses_hash( $transcript );
		return $transcript;
	}

	/**
	 * SHA-256 over transcript without the hash field itself.
	 *
	 * @param array<string,mixed> $transcript Transcript.
	 */
	public static function rses_hash( array $transcript ): string {
		$data = $transcript;
		unset( $data['public_transcript_hash'] );
		return HashService::rses_hash_json( $data );
	}

	/**
	 * Files to embed identically in ceremony-public and official ZIPs.
	 *
	 * @param array<string,mixed> $transcript Transcript with hash.
	 * @return array<string,string> path => contents
	 */
	public static function rses_public_files( array $transcript ): array {
		$commitments = array(
			'format_version'         => $transcript['format_version'] ?? '1.0',
			'scheme_id'              => $transcript['scheme_id'] ?? '',
			'ceremony_id'            => $transcript['ceremony_id'] ?? '',
			'commitments'            => $transcript['commitments'] ?? array(),
			'public_transcript_hash' => $transcript['public_transcript_hash'] ?? '',
		);

		$public_key = array(
			'format_version'         => $transcript['format_version'] ?? '1.0',
			'scheme_id'              => $transcript['scheme_id'] ?? '',
			'ceremony_id'            => $transcript['ceremony_id'] ?? '',
			'public_key'             => $transcript['public_key'] ?? array(),
			'public_transcript_hash' => $transcript['public_transcript_hash'] ?? '',
		);

		$participants = array(
			'format_version'         => $transcript['format_version'] ?? '1.0',
			'scheme_id'              => $transcript['scheme_id'] ?? '',
			'ceremony_id'            => $transcript['ceremony_id'] ?? '',
			'participants'           => $transcript['participants'] ?? array(),
			'threshold_t'            => $transcript['threshold_t'] ?? 0,
			'participant_count'      => $transcript['participant_count'] ?? 0,
			'public_transcript_hash' => $transcript['public_transcript_hash'] ?? '',
		);

		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

		return array(
			'ceremony-manifest.json'   => (string) wp_json_encode( $transcript, $flags ),
			'commitments.json'         => (string) wp_json_encode( $commitments, $flags ),
			'ceremony-public-key.json' => (string) wp_json_encode( $public_key, $flags ),
			'participants.json'        => (string) wp_json_encode( $participants, $flags ),
		);
	}
}
