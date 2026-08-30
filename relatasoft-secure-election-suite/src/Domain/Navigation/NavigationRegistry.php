<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Navigation;

use RelataSoft\SecureElectionSuite\Painel\Domain\Access\AccessPolicy;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\Persona;

/**
 * In-memory registry of Painel navigation items.
 */
final class NavigationRegistry {

	/** @var array<string, MenuItem> */
	private array $items = array();

	public function register(MenuItem $item): void {
		$this->items[ $item->id ] = $item;
	}

	public function unregister(string $id): void {
		unset( $this->items[ $id ] );
	}

	/**
	 * @return list<MenuItem>
	 */
	public function all(): array {
		$items = array_values( $this->items );
		usort(
			$items,
			static fn( MenuItem $a, MenuItem $b ): int => $a->priority <=> $b->priority
		);
		return $items;
	}

	/**
	 * @return list<MenuItem>
	 */
	public function visibleFor(Persona $persona, AccessPolicy $policy, string $mode = ''): array {
		$out = array();
		foreach ( $this->all() as $item ) {
			if ( '' !== $item->mode && '' !== $mode && $item->mode !== $mode && 'any' !== $item->mode ) {
				continue;
			}
			if ( $item->visibleForPermissions ) {
				$ok = false;
				foreach ( $item->visibleForPermissions as $perm ) {
					if ( $policy->can( $persona, $perm ) ) {
						$ok = true;
						break;
					}
				}
				if ( ! $ok ) {
					continue;
				}
			}
			$out[] = $item;
		}
		return $out;
	}
}
