<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Dashboard;

/**
 * A single home card on the mode-specific Painel home.
 */
final class ModeHomeCard {

	public function __construct(
		public readonly string $title,
		public readonly string $body,
		public readonly string $actionLabel,
		public readonly string $actionSlug,
		public readonly string $requiredPermission = '',
	) {}
}
