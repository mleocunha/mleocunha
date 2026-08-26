<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Platform;

interface Logger {
	/** @param array<string, scalar|null> $context */
	public function info(string $message, array $context = array()): void;

	/** @param array<string, scalar|null> $context */
	public function warning(string $message, array $context = array()): void;

	/** @param array<string, scalar|null> $context */
	public function error(string $message, array $context = array()): void;
}
