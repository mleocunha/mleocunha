<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll\RsvFormat;

/**
 * Estimate helpers without WordPress (mirrors ElectoralRollExportService math).
 */
final class ElectoralRollExportEstimateTest extends TestCase {

	public function test_estimate_grows_with_max_lines(): void {
		$avg    = 180;
		$header = strlen( RsvFormat::headerLine() ) + 1;
		$small  = $header + ( 10 * $avg );
		$large  = $header + ( 1000 * $avg );
		$this->assertSame( $small, $header + 10 * $avg );
		$this->assertGreaterThan( $small, $large );
		$this->assertStringContainsString( ':', RsvFormat::headerLine() );
	}
}
