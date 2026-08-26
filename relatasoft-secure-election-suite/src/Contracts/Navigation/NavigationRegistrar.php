<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Navigation;

use RelataSoft\SecureElectionSuite\Painel\Domain\Navigation\MenuItem;

interface NavigationRegistrar {
	/** @param list<MenuItem> $items */
	public function sync(array $items): void;
}
