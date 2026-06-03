<?php
/**
 * Ballot message encoding helpers.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse cast ballot payloads for tally.
 */
class EVote_Ballot_Payload {

	/**
	 * Classify ballot after decrypt (encoding in JSON is authoritative).
	 *
	 * @param array<string, mixed> $ballot         Stored ballot JSON.
	 * @param array<string, mixed> $decrypt_result From EVote_Crypto::decrypt_ballot.
	 * @return array{type: string, code: string|null}
	 */
	public static function classify( array $ballot, $decrypt_result ) {
		$enc = $ballot['message_encoding'] ?? '';
		if ( EVote_Modality_Registry::ENC_BLANK === $enc ) {
			return array( 'type' => 'blank', 'code' => null );
		}
		if ( EVote_Modality_Registry::ENC_NULL === $enc ) {
			return array( 'type' => 'null', 'code' => null );
		}
		if ( EVote_Modality_Registry::ENC_NUMBER === $enc || 'vote-integer' === $enc ) {
			$code = isset( $ballot['message'] ) ? EVote_Ballot_Codes::normalize_code( $ballot['message'] ) : null;
			if ( ! $code && ! empty( $decrypt_result['vote_integer'] ) ) {
				$code = EVote_Ballot_Codes::normalize_code( $decrypt_result['vote_integer'] );
			}
			return array( 'type' => 'vote', 'code' => $code );
		}
		return array( 'type' => 'unknown', 'code' => null );
	}
}
