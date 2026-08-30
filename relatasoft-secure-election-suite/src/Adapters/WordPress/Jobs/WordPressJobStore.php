<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Jobs;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobStore;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobSlots;

/**
 * Adapter #1: persist job state in host options (only place that touches get_option for jobs).
 */
final class WordPressJobStore implements JobStore {

	public function get(string $slot): ?array {
		$raw = get_option( $this->optionKey( $slot ), null );
		return is_array( $raw ) ? $raw : null;
	}

	public function put(string $slot, array $job): void {
		update_option( $this->optionKey( $slot ), $job, false );
	}

	public function delete(string $slot): void {
		delete_option( $this->optionKey( $slot ) );
	}

	/**
	 * Keep legacy option key names for in-flight / resume compatibility.
	 */
	private function optionKey(string $slot): string {
		if ( JobSlots::KEYGEN === $slot ) {
			return 'rses_keygen_job';
		}
		if ( str_starts_with( $slot, 'rsv_import:' ) ) {
			return 'rses_electoral_roll_job_' . substr( $slot, strlen( 'rsv_import:' ) );
		}
		if ( str_starts_with( $slot, 'rsv_export:' ) ) {
			return 'rses_electoral_roll_export_job_' . substr( $slot, strlen( 'rsv_export:' ) );
		}
		return 'rses_job_' . sanitize_key( str_replace( ':', '_', $slot ) );
	}
}
