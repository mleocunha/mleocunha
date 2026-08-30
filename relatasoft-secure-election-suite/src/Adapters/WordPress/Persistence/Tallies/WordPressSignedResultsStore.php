<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Persistence\Tallies;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Tallies\SignedResultsStore;

/**
 * Adapter #1: signed-results metadata in host options.
 */
final class WordPressSignedResultsStore implements SignedResultsStore {

	public static function optionKey( int $importId ): string {
		return 'rses_signed_persist_' . $importId;
	}

	public function get( int $importId ): ?array {
		$meta = get_option( self::optionKey( $importId ), null );
		return is_array( $meta ) ? $meta : null;
	}

	public function put( int $importId, array $meta ): void {
		update_option( self::optionKey( $importId ), $meta, false );
	}

	public function delete( int $importId ): void {
		delete_option( self::optionKey( $importId ) );
	}
}
