<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\Logging;

use RelataSoft\SecureElectionSuite\Painel\Contracts\Platform\Logger;

final class WordPressLogger implements Logger {

	/** @param array<string, scalar|null> $context */
	public function info(string $message, array $context = array()): void {
		$this->write( 'info', $message, $context );
	}

	/** @param array<string, scalar|null> $context */
	public function warning(string $message, array $context = array()): void {
		$this->write( 'warning', $message, $context );
	}

	/** @param array<string, scalar|null> $context */
	public function error(string $message, array $context = array()): void {
		$this->write( 'error', $message, $context );
	}

	/** @param array<string, scalar|null> $context */
	private function write(string $level, string $message, array $context): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[ve-painel][' . $level . '] ' . $message . ' ' . wp_json_encode( $context ) );
		}
	}
}
