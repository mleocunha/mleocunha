<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs;

/**
 * Stable slot names for {@see JobStore}.
 */
final class JobSlots {
	public const KEYGEN = 'keygen';

	public static function rsvImport(int $ownerId): string {
		return 'rsv_import:' . max(0, $ownerId);
	}

	public static function rsvExport(int $ownerId): string {
		return 'rsv_export:' . max(0, $ownerId);
	}
}
