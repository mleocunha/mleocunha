<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Standalone;

use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\NodeRuntime;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\VoteMaterialPackage;

/**
 * Exportar boletins cifrados do nó de votação (descarregar na sessão — sem Courier).
 */
final class VoteMaterialExportService {

	public const VOTE_MATERIAL_FILE = 'vote-material.json';

	/**
	 * @return array<string,mixed> Pacote VoteMaterialPackage
	 */
	public static function buildFromVotes(
		NodeRuntime $voting,
		int $electionId,
		int $roundId,
		string $publicKeyChecksum
	): array {
		$voting->requireMode( SiteModes::VOTING );

		$ballots = array();
		$voting->persistence->votes->forEachExportRow(
			$roundId,
			static function ( array $row ) use ( &$ballots ): void {
				$alpha = (string) ( $row['ciphertext_alpha'] ?? '' );
				$beta  = (string) ( $row['ciphertext_beta'] ?? '' );
				if ( '' === $alpha || '' === $beta ) {
					return;
				}
				$ballots[] = array(
					'voter_id'    => (int) ( $row['voter_user_id'] ?? $row['id'] ?? 0 ),
					'question_id' => (int) ( $row['question_id'] ?? 0 ),
					'alpha'       => $alpha,
					'beta'        => $beta,
					'receipt'     => (string) ( $row['vote_hash'] ?? '' ),
				);
			}
		);

		if ( empty( $ballots ) ) {
			throw new \RuntimeException( 'Nenhum voto para exportar neste turno.' );
		}

		return VoteMaterialPackage::build(
			array(
				'election_id'         => $electionId,
				'round_id'            => $roundId,
				'public_key_checksum' => $publicKeyChecksum,
				'ballots'             => $ballots,
				'source_mode'         => SiteModes::VOTING,
				'cliente_id'          => $voting->clienteId,
				'cliente_nome'        => $voting->clienteId,
			)
		);
	}

	/**
	 * @param array<string,mixed> $package
	 */
	public static function toJson( array $package ): string {
		return VoteMaterialPackage::toJson( $package );
	}
}
