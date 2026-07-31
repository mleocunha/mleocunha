<?php
/**
 * Nonce verification utilities.
 *
 * @package RelataSoft\SecureElectionSuite\Security
 */

namespace RelataSoft\SecureElectionSuite\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Nonce helpers for state-changing requests.
 */
class Nonce {

	/**
	 * Known nonce actions.
	 */
	public const RSES_ACTION_MODE_SET           = 'rses_mode_set';
	public const RSES_ACTION_DESTRUCTIVE_RESET  = 'rses_destructive_reset';
	public const RSES_ACTION_KEY_GENERATE       = 'rses_key_generate';
	public const RSES_ACTION_KEY_IMPORT         = 'rses_key_import';
	public const RSES_ACTION_KEY_EXPORT         = 'rses_key_export';
	public const RSES_ACTION_SHARE_EXPORT       = 'rses_share_export';
	public const RSES_ACTION_MEDIA_ATTACH       = 'rses_media_attach';
	public const RSES_ACTION_ELECTION_SAVE      = 'rses_election_save';
	public const RSES_ACTION_ELECTION_DELETE    = 'rses_election_delete';
	public const RSES_ACTION_VOTE_CAST          = 'rses_vote_cast';
	public const RSES_ACTION_TALLY_IMPORT       = 'rses_tally_import';
	public const RSES_ACTION_SHARE_SUBMIT       = 'rses_share_submit';
	public const RSES_ACTION_DECRYPTION         = 'rses_decryption';
	public const RSES_ACTION_CERTIFICATION      = 'rses_certification';
	public const RSES_ACTION_CRYPTO_SELF_TEST   = 'rses_crypto_self_test';
	public const RSES_ACTION_SETTINGS_SAVE      = 'rses_settings_save';
	public const RSES_ACTION_VOTING_EXPORT      = 'rses_voting_export';
	public const RSES_ACTION_BALLOT_SAVE        = 'rses_ballot_save';
	public const RSES_ACTION_REDIRECTIONS_SAVE       = 'rses_redirections_save';
	public const RSES_ACTION_JOURNEY_PROVISION       = 'rses_journey_provision';
	public const RSES_ACTION_ELECTORAL_ROLL_IMPORT   = 'rses_electoral_roll_import';
	public const RSES_ACTION_ELECTORAL_ROLL_SAMPLE   = 'rses_electoral_roll_sample';

	/**
	 * Output a nonce field for forms.
	 *
	 * Echoes the hidden inputs (WordPress default). Call sites use
	 * `<?php Nonce::rses_field( ... ); ?>` and must not need echo.
	 *
	 * @param string $action Nonce action.
	 */
	public static function rses_field( string $action ): void {
		wp_nonce_field( $action, '_rses_nonce', true, true );
	}

	/**
	 * Create a nonce URL.
	 *
	 * @param string $url    URL.
	 * @param string $action Nonce action.
	 * @return string
	 */
	public static function rses_url( string $url, string $action ): string {
		return wp_nonce_url( $url, $action, '_rses_nonce' );
	}

	/**
	 * Verify nonce from request.
	 *
	 * @param string $action Nonce action.
	 * @return bool
	 */
	public static function rses_verify( string $action ): bool {
		$rses_nonce = isset( $_REQUEST['_rses_nonce'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['_rses_nonce'] ) )
			: '';

		if ( empty( $rses_nonce ) && isset( $_REQUEST['_wpnonce'] ) ) {
			$rses_nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
		}

		return (bool) wp_verify_nonce( $rses_nonce, $action );
	}

	/**
	 * Verify nonce or die.
	 *
	 * @param string $action Nonce action.
	 */
	public static function rses_verify_or_die( string $action ): void {
		if ( ! self::rses_verify( $action ) ) {
			wp_die(
				esc_html__( 'Security check failed. Please try again.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Invalid Nonce', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}
	}
}
