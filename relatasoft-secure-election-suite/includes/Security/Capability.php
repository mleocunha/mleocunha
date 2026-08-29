<?php
/**
 * Capability checks for role mapping.
 *
 * @package RelataSoft\SecureElectionSuite\Security
 */

namespace RelataSoft\SecureElectionSuite\Security;

use RelataSoft\SecureElectionSuite\I18n\RoleLabels;
use RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User\WordPressCapabilityResolver;
use RelataSoft\SecureElectionSuite\Painel\Application\Identity\IdentityGateway;

defined( 'ABSPATH' ) || exit;

/**
 * Election capability facade (A3) — delegates to {@see IdentityGateway} ports.
 */
class Capability {

	public const RSES_VOTER_ROLE    = WordPressCapabilityResolver::VOTER_ROLE;
	public const RSES_ADMIN_ROLE    = WordPressCapabilityResolver::ADMIN_ROLE;
	public const RSES_OFFICIAL_ROLE = WordPressCapabilityResolver::OFFICIAL_ROLE;

	/**
	 * Whether a user has the Administrator role (or gestor / manage_options).
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 */
	public static function rses_user_has_admin_role( ?int $user_id = null ): bool {
		return IdentityGateway::get()->capabilities->hasAdminRole( $user_id );
	}

	/**
	 * Whether the user may view the audit log (admin/gestor OR auditor).
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 */
	public static function rses_can_view_audit( ?int $user_id = null ): bool {
		return IdentityGateway::get()->capabilities->canViewAudit( $user_id );
	}

	/**
	 * Require audit-view capability or die.
	 */
	public static function rses_require_audit_view(): void {
		if ( ! self::rses_can_view_audit( null ) ) {
			wp_die(
				esc_html__( 'Só quem administra a eleição ou audita o processo pode ver o registro de auditoria.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Sem permissão', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Whether a user is an election official eligible for Shamir share custody.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 */
	public static function rses_user_has_official_role( ?int $user_id = null ): bool {
		return IdentityGateway::get()->capabilities->hasOfficialRole( $user_id );
	}

	/**
	 * Election administrator: Administrator / gestor.
	 */
	public static function rses_can_manage_election(): bool {
		return IdentityGateway::get()->capabilities->canManageElection( null );
	}

	/**
	 * Whether the user may run tally decryption and certification.
	 */
	public static function rses_can_tally_and_certify(): bool {
		return self::rses_user_has_admin_role( null );
	}

	/**
	 * Election official / Shamir share holder (Editor or Administrator).
	 */
	public static function rses_is_election_official(): bool {
		return self::rses_user_has_official_role( null );
	}

	/**
	 * Candidate (contributor-like).
	 */
	public static function rses_is_candidate(): bool {
		return IdentityGateway::get()->capabilities->isCandidate( null );
	}

	/**
	 * Whether a user is enrolled as a voter (subscriber role).
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 */
	public static function rses_user_has_voter_role( ?int $user_id = null ): bool {
		return IdentityGateway::get()->capabilities->hasVoterRole( $user_id );
	}

	/**
	 * Voter eligibility: enrolled with the subscriber role.
	 */
	public static function rses_can_vote(): bool {
		return IdentityGateway::get()->capabilities->canVote( null );
	}

	/**
	 * Can export own Shamir share.
	 */
	public static function rses_can_export_own_share(): bool {
		return IdentityGateway::get()->capabilities->canExportOwnShare( null );
	}

	/**
	 * Can export all shares (admin only + settings flag).
	 */
	public static function rses_can_export_all_shares(): bool {
		return IdentityGateway::get()->capabilities->canExportAllShares( null );
	}

	/**
	 * Require Administrator role or die.
	 */
	public static function rses_require_admin(): void {
		if ( ! self::rses_can_manage_election() ) {
			wp_die(
				esc_html__( 'Só quem administra a eleição pode fazer isso.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Sem permissão', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Require Administrator role for tally/certify operations or die.
	 */
	public static function rses_require_tally_admin(): void {
		if ( ! self::rses_can_tally_and_certify() ) {
			wp_die(
				esc_html__( 'Only users with the Administrator role may import tallies, decrypt results, or certify an election. Election officials may submit Shamir shares only.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Permission Denied', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Require Editor (or Administrator) role for official share actions or die.
	 */
	public static function rses_require_official(): void {
		if ( ! self::rses_is_election_official() ) {
			wp_die(
				esc_html( RoleLabels::rses_message_official_required() ),
				esc_html__( 'Permission Denied', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Require enrolled voter (subscriber role) or die.
	 */
	public static function rses_require_voter(): void {
		if ( ! self::rses_can_vote() ) {
			wp_die(
				esc_html( RoleLabels::rses_message_vote_denied_full() ),
				esc_html__( 'Permission Denied', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}
	}
}
