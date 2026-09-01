<?php
/**
 * Locale-specific typed confirmation for destructive admin actions.
 *
 * @package RelataSoft\SecureElectionSuite\Security
 */

namespace RelataSoft\SecureElectionSuite\Security;

defined( 'ABSPATH' ) || exit;

/**
 * English source word is “confirm”; catalogs map it (e.g. pt_BR → “confirmo”).
 */
class ConfirmWord {

	/**
	 * Word an administrator must type to confirm a destructive action.
	 */
	public static function rses_word(): string {
		return (string) __( 'confirm', 'relatasoft-secure-election-suite' );
	}

	/**
	 * Whether typed text matches the required confirmation word (case-sensitive).
	 *
	 * @param string $typed User input.
	 */
	public static function rses_matches( string $typed ): bool {
		$rses_expected = self::rses_word();
		$rses_typed    = trim( wp_unslash( $typed ) );
		if ( '' === $rses_expected || '' === $rses_typed ) {
			return false;
		}
		return $rses_typed === $rses_expected;
	}
}
