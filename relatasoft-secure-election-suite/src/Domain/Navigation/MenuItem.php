<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Navigation;

/**
 * Platform-agnostic admin navigation item.
 */
final class MenuItem {

	/**
	 * @param list<string> $visibleForPermissions
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $slug,
		public readonly string $parentId = '',
		public readonly int $priority = 10,
		public readonly string $icon = '',
		public readonly string $url = '',
		public readonly array $visibleForPermissions = array(),
		public readonly string $mode = '',
	) {}
}
