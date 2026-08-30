<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence;

/**
 * Convert WordPress DB row objects to port arrays.
 */
final class RowMapper {

	/** @return array<string,mixed>|null */
	public static function toArray( ?object $row ): ?array {
		return null === $row ? null : (array) $row;
	}

	/**
	 * @param list<object> $rows
	 * @return list<array<string,mixed>>
	 */
	public static function toArrays( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = (array) $row;
		}
		return $out;
	}
}
