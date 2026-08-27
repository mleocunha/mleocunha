<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\User;

/**
 * Registers the custom WP role for Auditor (read-only electoral oversight).
 */
final class AuditorRoleRegistrar {

	public static function register(): void {
		// Role creation is idempotent on init/activation.
	}

	public static function ensureRole(): void {
		if ( get_role( WordPressCapabilityResolver::AUDITOR_ROLE ) ) {
			return;
		}
		// Auditor: read WP admin shell pages that RSES capability helpers open;
		// no manage_options — softens blast radius vs administrator.
		add_role(
			WordPressCapabilityResolver::AUDITOR_ROLE,
			'Auditor',
			array(
				'read'      => true,
				'edit_posts'=> true, // Required so WP admin menu gate (edit_posts) admits the role.
			)
		);
	}
}
