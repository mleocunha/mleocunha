<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Widget;

/**
 * Registry for Painel dashboard widgets (platform-agnostic).
 *
 * RSES and other modules register widgets via Application services; adapters render.
 */
final class WidgetRegistry {

	/** @var array<string, object> */
	private array $widgets = array();

	public function register(string $id, object $widget): void {
		$this->widgets[ $id ] = $widget;
	}

	public function unregister(string $id): void {
		unset( $this->widgets[ $id ] );
	}

	public function resolve(string $id): ?object {
		return $this->widgets[ $id ] ?? null;
	}

	/** @return array<string, object> */
	public function all(): array {
		return $this->widgets;
	}
}
