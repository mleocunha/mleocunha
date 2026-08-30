<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Domain\ElectoralRoll\RsvFormat;

final class RsvFormatTest extends TestCase {

	public function test_header_and_roundtrip(): void {
		$header = RsvFormat::headerLine();
		$this->assertStringContainsString( 'login:', $header );
		$this->assertStringEndsWith( ':senha', $header );

		$fields = array(
			'ana.silva.0001',
			'12345678900',
			'123456789012',
			'Zona 45',
			'Seção 0123',
			'Ana Silva',
			'+5511999990001;+5511888880002',
			'ana@ex.com;ana.alt@ex.com',
			'Rua X, 10, Recife-PE',
			'eleitor',
			'SenhaForte1',
		);
		$line = RsvFormat::serializeLine( $fields );
		$parsed = RsvFormat::parseLine( $line );
		$this->assertSame( $fields, $parsed );
		$assoc = RsvFormat::associate( $parsed );
		$this->assertSame( 'Rua X, 10, Recife-PE', $assoc['endereco'] );
		$this->assertSame( array( '+5511999990001', '+5511888880002' ), RsvFormat::splitSeries( $assoc['celular'] ) );
	}

	public function test_rejects_wrong_field_count(): void {
		$this->assertNull( RsvFormat::parseLine( 'a:b:c' ) );
	}

	public function test_role_map(): void {
		$this->assertSame( 'subscriber', RsvFormat::mapRole( 'eleitor' ) );
		$this->assertSame( 've_auditor', RsvFormat::mapRole( 'auditor' ) );
		$this->assertSame( 've_auditor', RsvFormat::mapRole( 'customer' ) );
		$this->assertSame( 'editor', RsvFormat::mapRole( 'autoridade' ) );
		$this->assertNull( RsvFormat::mapRole( 'desconhecido' ) );
	}

	public function test_reverse_role(): void {
		$this->assertSame( 'eleitor', RsvFormat::reverseRole( 'subscriber' ) );
		$this->assertSame( 'auditor', RsvFormat::reverseRole( 've_auditor' ) );
		$this->assertSame( 'autoridade', RsvFormat::reverseRole( 'editor' ) );
		$this->assertSame( 'administrador', RsvFormat::reverseRole( 'administrator' ) );
		$this->assertSame( 'gestor', RsvFormat::reverseRole( 've_gestor' ) );
		$this->assertSame( '', RsvFormat::reverseRole( 'author' ) );
	}

	public function test_adaptive_chunk_respects_ceiling_and_cap(): void {
		$this->assertSame( 256 * 1024, RsvFormat::adaptiveChunkBytes( 0 ) );
		$this->assertLessThanOrEqual( 1024 * 1024, RsvFormat::adaptiveChunkBytes( 50 * 1024 * 1024 ) );
		$this->assertGreaterThanOrEqual( 64 * 1024, RsvFormat::adaptiveChunkBytes( 100 * 1024 ) );
		$this->assertSame( 4 * 1024 * 1024 * 1024, RsvFormat::maxUploadBytes() );
	}
}
