<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Standalone;

use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ElGamalCiphertext;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\HomomorphicTally;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ShamirSecretSharing;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\VoteMaterialPackage;

/**
 * Reconstrução Shamir + agregação homomórfica + descriptografia do total.
 *
 * Partilhado pelo piloto CLI e pela certificação HTTP (sem pasta courier comum).
 */
final class HomomorphicCertifyService {

	/**
	 * @param array<string,mixed>              $votePkg        Pacote vote-material validado.
	 * @param list<array<string,mixed>>        $sharePayloads  Parcelas submetidas (payload JSON).
	 * @param array{p:string,q:string,g:string,y:string} $publicKey
	 * @return array{tally:int,election_id:int,round_id:int,ballots:int,threshold:int}
	 */
	public static function decryptTally(
		array $votePkg,
		array $sharePayloads,
		array $publicKey,
		?string $fieldPrimeOverride = null
	): array {
		$v = VoteMaterialPackage::validate( $votePkg );
		if ( empty( $v['ok'] ) ) {
			throw new \RuntimeException( 'Material de voto inválido: ' . ( $v['error'] ?? '?' ) );
		}
		if ( empty( $sharePayloads ) ) {
			throw new \RuntimeException( 'Nenhuma parcela submetida.' );
		}

		$threshold = (int) ( $sharePayloads[0]['threshold_t'] ?? 0 );
		if ( $threshold < 1 ) {
			throw new \RuntimeException( 'Limiar Shamir ausente nas parcelas.' );
		}
		if ( count( $sharePayloads ) < $threshold ) {
			throw new \RuntimeException(
				sprintf( 'Parcelas insuficientes: %d / limiar %d.', count( $sharePayloads ), $threshold )
			);
		}

		$fieldPrime = '' !== (string) ( $fieldPrimeOverride ?? '' )
			? (string) $fieldPrimeOverride
			: (string) ( $sharePayloads[0]['field_prime'] ?? '' );
		if ( '' === $fieldPrime ) {
			throw new \RuntimeException( 'field_prime ausente nas parcelas.' );
		}

		$sharePoints = array();
		$seenIndex   = array();
		foreach ( $sharePayloads as $payload ) {
			$idx = (int) ( $payload['share_index'] ?? 0 );
			if ( $idx < 1 || isset( $seenIndex[ $idx ] ) ) {
				continue;
			}
			$seenIndex[ $idx ] = true;
			$sharePoints[]     = array(
				'x' => $idx,
				'y' => BigInt::fromDecimalString( (string) ( $payload['share_value'] ?? '' ) ),
			);
			if ( count( $sharePoints ) >= $threshold ) {
				break;
			}
		}
		if ( count( $sharePoints ) < $threshold ) {
			throw new \RuntimeException( 'Índices de parcela insuficientes para o limiar.' );
		}

		$field = BigInt::fromDecimalString( $fieldPrime );
		$x     = ShamirSecretSharing::reconstructWithThreshold( $sharePoints, $field, $threshold );

		$p = BigInt::fromDecimalString( (string) $publicKey['p'] );
		$q = BigInt::fromDecimalString( (string) $publicKey['q'] );
		$g = BigInt::fromDecimalString( (string) $publicKey['g'] );
		$y = BigInt::fromDecimalString( (string) $publicKey['y'] );

		$yCheck = BigInt::modPow( $g, $x, $p );
		if ( 0 !== \gmp_cmp( $yCheck, $y ) ) {
			throw new \RuntimeException( 'Segredo reconstruído não corresponde à chave pública.' );
		}

		$ciphertexts = array();
		foreach ( $votePkg['ballots'] as $ballot ) {
			if ( ! is_array( $ballot ) ) {
				continue;
			}
			$ciphertexts[] = new ElGamalCiphertext(
				BigInt::fromDecimalString( (string) ( $ballot['alpha'] ?? '' ) ),
				BigInt::fromDecimalString( (string) ( $ballot['beta'] ?? '' ) )
			);
		}
		if ( empty( $ciphertexts ) ) {
			throw new \RuntimeException( 'Nenhum boletim cifrado no material.' );
		}

		$sum   = HomomorphicTally::aggregateCounts( $ciphertexts, $p );
		$tally = HomomorphicTally::decryptAndDecode( $sum, $p, $q, $g, $x, max( 10, count( $ciphertexts ) + 5 ) );

		return array(
			'tally'       => $tally,
			'election_id' => (int) ( $votePkg['election_id'] ?? 0 ),
			'round_id'    => (int) ( $votePkg['round_id'] ?? 0 ),
			'ballots'     => count( $ciphertexts ),
			'threshold'   => $threshold,
		);
	}
}
