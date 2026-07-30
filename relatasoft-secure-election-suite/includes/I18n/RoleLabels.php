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
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'rses_rename_wp_roles' ), 11 );
		add_filter( 'translate_user_role', array( self::class, 'rses_translate_user_role' ), 10, 3 );
		add_filter( 'gettext', array( self::class, 'rses_filter_gettext' ), 20, 3 );
		add_filter( 'gettext_with_context', array( self::class, 'rses_filter_gettext_with_context' ), 20, 4 );
	}

	/**
	 * Plural role label shown in WordPress role lists.
	 */
	public static function rses_editor_plural(): string {
		return __( 'Electoral Authorities', 'relatasoft-secure-election-suite' );
	}

	/**
	 * Singular label for one official account.
	 */
	public static function rses_editor_singular(): string {
		return __( 'Electoral Authority', 'relatasoft-secure-election-suite' );
	}

	/**
	 * Plural role label for elector accounts.
	 */
	public static function rses_elector_plural(): string {
		return __( 'Electors', 'relatasoft-secure-election-suite' );
	}

	/**
	 * Singular label for one elector account.
	 */
	public static function rses_elector_singular(): string {
		return __( 'Elector', 'relatasoft-secure-election-suite' );
	}

	/**
	 * Rename built-in role display names.
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
			$rses_roles->roles['editor']['name'] = self::rses_editor_plural();
			$rses_roles->role_names['editor']    = self::rses_editor_plural();
		}

		if ( isset( $rses_roles->roles['subscriber'] ) ) {
			$rses_roles->roles['subscriber']['name'] = self::rses_elector_plural();
			$rses_roles->role_names['subscriber']  = self::rses_elector_plural();
		}
	}

	/**
	 * Filter role names from translate_user_role().
	 *
	 * @param string $translation Translated role name.
	 * @param string $role        Raw role name from database.
	 * @param string $context     Context.
	 */
	public static function rses_translate_user_role( string $translation, string $role, string $context ): string {
		unset( $context );

		$rses_slug = sanitize_key( $role );
		if ( 'editor' === $rses_slug ) {
			return self::rses_editor_plural();
		}
		if ( 'subscriber' === $rses_slug ) {
			return self::rses_elector_plural();
		}

		return $translation;
	}

	/**
	 * Replace core role names in the default text domain.
	 *
	 * @param string $translation Translated string.
	 * @param string $text        Source string.
	 * @param string $domain      Text domain.
	 */
	public static function rses_filter_gettext( string $translation, string $text, string $domain ): string {
		if ( 'default' !== $domain ) {
			return $translation;
		}

		return self::rses_map_default_role_string( $text, $translation );
	}

	/**
	 * Replace core role names when WordPress supplies context.
	 *
	 * @param string $translation Translated string.
	 * @param string $text        Source string.
	 * @param string $context     Context.
	 * @param string $domain      Text domain.
	 */
	public static function rses_filter_gettext_with_context( string $translation, string $text, string $context, string $domain ): string {
		if ( 'default' !== $domain ) {
			return $translation;
		}

		if ( 'User role' === $context || 'default' === $context ) {
			return self::rses_map_default_role_string( $text, $translation );
		}

		return $translation;
	}

	/**
	 * Map a default-domain source string to relabeled role names.
	 *
	 * @param string $text         Original English source.
	 * @param string $translation  Current translation (fallback).
	 */
	private static function rses_map_default_role_string( string $text, string $translation ): string {
		$rses_map = array(
			'Editor'     => self::rses_editor_plural(),
			'Subscriber' => self::rses_elector_plural(),
		);

		if ( isset( $rses_map[ $text ] ) ) {
			return $rses_map[ $text ];
		}

		return $translation;
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
