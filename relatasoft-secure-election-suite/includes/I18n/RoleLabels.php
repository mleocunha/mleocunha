<?php
/**
 * Display labels for WordPress roles (slug unchanged).
 *
 * Maps editor → Electoral Authorities, subscriber → Electors across WP and plugin UI.
 *
 * @package RelataSoft\SecureElectionSuite\I18n
 */

namespace RelataSoft\SecureElectionSuite\I18n;

defined( 'ABSPATH' ) || exit;

/**
 * Role label overrides (presentation only).
 */
class RoleLabels {

	/**
	 * Guard against gettext recursion (calling __() inside a gettext filter).
	 *
	 * @var bool
	 */
	private static bool $rses_busy = false;

	/**
	 * English display names stored on wp_roles (User role gettext msgids).
	 */
	private const RSES_EDITOR_EN    = 'Electoral Authorities';
	private const RSES_ELECTOR_EN   = 'Electors';
	private const RSES_EDITOR_ONE   = 'Electoral Authority';
	private const RSES_ELECTOR_ONE  = 'Elector';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'rses_rename_wp_roles' ), 11 );
		add_filter( 'gettext', array( self::class, 'rses_filter_gettext' ), 20, 3 );
		add_filter( 'gettext_with_context', array( self::class, 'rses_filter_gettext_with_context' ), 20, 4 );
	}

	/**
	 * Plural role label shown in WordPress role lists.
	 */
	public static function rses_editor_plural(): string {
		return self::rses_translate_label( self::RSES_EDITOR_EN );
	}

	/**
	 * Singular label for one official account.
	 */
	public static function rses_editor_singular(): string {
		return self::rses_translate_label( self::RSES_EDITOR_ONE );
	}

	/**
	 * Plural role label for elector accounts.
	 */
	public static function rses_elector_plural(): string {
		return self::rses_translate_label( self::RSES_ELECTOR_EN );
	}

	/**
	 * Singular label for one elector account.
	 */
	public static function rses_elector_singular(): string {
		return self::rses_translate_label( self::RSES_ELECTOR_ONE );
	}

	/**
	 * Translate a role label without re-entering gettext filters.
	 *
	 * @param string $rses_english English msgid.
	 */
	private static function rses_translate_label( string $rses_english ): string {
		if ( self::$rses_busy ) {
			return $rses_english;
		}

		self::$rses_busy = true;
		$rses_out        = __( $rses_english, 'relatasoft-secure-election-suite' );
		self::$rses_busy = false;

		return $rses_out;
	}

	/**
	 * Rename built-in role display names (English msgids; i18n via User role context).
	 */
	public static function rses_rename_wp_roles(): void {
		if ( ! function_exists( 'wp_roles' ) ) {
			return;
		}

		$rses_roles = wp_roles();
		if ( ! $rses_roles instanceof \WP_Roles ) {
			return;
		}

		if ( isset( $rses_roles->roles['editor'] ) ) {
			$rses_roles->roles['editor']['name'] = self::RSES_EDITOR_EN;
			$rses_roles->role_names['editor']    = self::RSES_EDITOR_EN;
		}

		if ( isset( $rses_roles->roles['subscriber'] ) ) {
			$rses_roles->roles['subscriber']['name'] = self::RSES_ELECTOR_EN;
			$rses_roles->role_names['subscriber']    = self::RSES_ELECTOR_EN;
		}
	}

	/**
	 * Replace core role names in the default text domain (exact msgid only).
	 *
	 * @param string $translation Translated string.
	 * @param string $text        Source string.
	 * @param string $domain      Text domain.
	 */
	public static function rses_filter_gettext( string $translation, string $text, string $domain ): string {
		if ( self::$rses_busy || 'default' !== $domain ) {
			return $translation;
		}

		return self::rses_map_role_msgid( $text, $translation );
	}

	/**
	 * Replace core role names when WordPress supplies User role context.
	 *
	 * @param string $translation Translated string.
	 * @param string $text        Source string.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 */
	public static function rses_filter_gettext_with_context( string $translation, string $text, string $context, string $domain ): string {
		if ( self::$rses_busy || 'default' !== $domain || 'User role' !== $context ) {
			return $translation;
		}

		return self::rses_map_role_msgid( $text, $translation );
	}

	/**
	 * Map a role msgid to the RelataSoft display label.
	 *
	 * @param string $text         Original English source.
	 * @param string $translation  Current translation (fallback).
	 */
	private static function rses_map_role_msgid( string $text, string $translation ): string {
		switch ( $text ) {
			case 'Editor':
			case self::RSES_EDITOR_EN:
				return self::rses_editor_plural();
			case 'Subscriber':
			case self::RSES_ELECTOR_EN:
				return self::rses_elector_plural();
			default:
				return $translation;
		}
	}

	/**
	 * Vote denied: elector role required (short).
	 */
	public static function rses_message_vote_denied_short(): string {
		return sprintf(
			/* translators: %s: elector role label (Elector) */
			__( 'Only users enrolled with the %s role may cast a ballot.', 'relatasoft-secure-election-suite' ),
			self::rses_elector_singular()
		);
	}

	/**
	 * Vote denied: elector role required (full, for wp_die / AJAX).
	 */
	public static function rses_message_vote_denied_full(): string {
		return sprintf(
			/* translators: 1: elector role label, 2: electoral authority role label */
			__( 'Only users enrolled with the %1$s role may cast a ballot. Administrator and %2$s accounts cannot vote unless they also have the %1$s role.', 'relatasoft-secure-election-suite' ),
			self::rses_elector_singular(),
			self::rses_editor_singular()
		);
	}

	/**
	 * Vote denied on booth screen (with sign-in hint).
	 */
	public static function rses_message_vote_denied_booth(): string {
		return sprintf(
			/* translators: 1: elector role label, 2: electoral authority role label */
			__( 'Only users enrolled with the %1$s role may cast a ballot. Sign in with an %1$s account (Administrator and %2$s accounts are not eligible unless they also have the %1$s role).', 'relatasoft-secure-election-suite' ),
			self::rses_elector_singular(),
			self::rses_editor_singular()
		);
	}

	/**
	 * Official share permission denied.
	 */
	public static function rses_message_official_required(): string {
		return sprintf(
			/* translators: %s: electoral authority role label */
			__( 'Only users with the %s role (election officials) may receive or submit Shamir shares. Administrators who were assigned a share may also access their own share.', 'relatasoft-secure-election-suite' ),
			self::rses_editor_singular()
		);
	}
}
