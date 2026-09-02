<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Jobs;

use RelataSoft\SecureElectionSuite\Painel\Application\Persistence\PersistenceGateway;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobSlots;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\JobStore;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Jobs\KeygenJobService;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\CryptoRandom;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\DeterministicRandom;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ElGamal;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\PrimeGenerator;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ShamirSecretSharing;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\MaterialCourier;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\PublicKeyPackage;

/**
 * Keygen chunked + persistente sob VE_DATA (sobrevive a logout / fecho do browser).
 *
 * Estágios alinhados ao KeyGenerationRunner WordPress; persistência/ Shamir / courier
 * usam o PersistenceGateway standalone.
 */
final class StandaloneKeygenJobService implements KeygenJobService {

	public const CHUNK_SECONDS = 20.0;
	public const TTL_SECONDS   = 86400;

	public const STAGE_SAFE_PRIME = 'safe_prime';
	public const STAGE_GENERATOR  = 'generator';
	public const STAGE_KEYPAIR    = 'keypair';
	public const STAGE_PERSIST    = 'persist';
	public const STAGE_SHAMIR     = 'shamir';
	public const STAGE_COMPLETE   = 'complete';
	public const STAGE_CANCELLED  = 'cancelled';
	public const STAGE_FAILED     = 'failed';

	/** @var list<string> */
	private const ACTIVE = array(
		self::STAGE_SAFE_PRIME,
		self::STAGE_GENERATOR,
		self::STAGE_KEYPAIR,
		self::STAGE_PERSIST,
		self::STAGE_SHAMIR,
	);

	public function __construct(
		private readonly JobStore $store,
		private readonly PersistenceGateway $persistence,
		private readonly string $courierDir,
		private readonly string $clienteId,
		private readonly float $chunkSeconds = self::CHUNK_SECONDS,
	) {}

	public function start( array $params ): array {
		$this->purgeExpired();
		if ( $this->hasActive() ) {
			return array_merge(
				$this->status(),
				array(
					'ok'      => false,
					'error'   => 'Já existe uma geração de chave em curso.',
					'message' => 'Já existe uma geração de chave em curso.',
				)
			);
		}

		$bits = (int) ( $params['bits'] ?? 0 );
		$th   = (int) ( $params['threshold_t'] ?? $params['threshold'] ?? 0 );
		$n    = (int) ( $params['total_n'] ?? $params['shares'] ?? 0 );
		$officials = array_values( array_map( 'intval', $params['officials'] ?? array() ) );
		$label = trim( (string) ( $params['label'] ?? $params['key_title'] ?? '' ) );
		$tech  = trim( (string) ( $params['key_label'] ?? '' ) );
		if ( '' === $label ) {
			$label = 'Eleição ' . gmdate( 'Y-m-d H:i' );
		}
		if ( '' === $tech ) {
			$tech = 've-' . gmdate( 'Ymd-His' );
		}

		$seed = CryptoRandom::randomBytes( 32 );
		$job  = array(
			'job_id'             => bin2hex( CryptoRandom::randomBytes( 8 ) ),
			'stage'              => self::STAGE_SAFE_PRIME,
			'progress'           => 1,
			'message'            => 'Iniciar procura de primo seguro…',
			'seed_hex'           => bin2hex( $seed ),
			'bits'               => $bits,
			'label'              => $label,
			'key_label'          => $tech,
			'display_name'       => $label,
			'threshold_t'        => $th,
			'total_n'            => $n,
			'officials'          => $officials,
			'safe_prime_attempt' => 0,
			'attempts_done'      => 0,
			'created_at'         => time(),
			'updated_at'         => time(),
			'public_p'           => null,
			'public_q'           => null,
			'public_g'           => null,
			'public_y'           => null,
			'private_x'          => null,
			'field_prime'        => null,
			'key_id'             => null,
			'error'              => null,
			'worker_pid'         => null,
		);
		$this->store->put( JobSlots::KEYGEN, $job );
		return array_merge( $this->publicStatus( $job ), array( 'ok' => true ) );
	}

	public function tick(): array {
		$this->purgeExpired();
		$job = $this->store->get( JobSlots::KEYGEN );
		if ( null === $job ) {
			return $this->publicStatus( null );
		}
		$stage = (string) ( $job['stage'] ?? '' );
		if ( in_array( $stage, array( self::STAGE_COMPLETE, self::STAGE_FAILED, self::STAGE_CANCELLED ), true ) ) {
			return $this->publicStatus( $job );
		}

		$deadline = microtime( true ) + max( 0.5, $this->chunkSeconds );
		$jobId    = (string) ( $job['job_id'] ?? '' );

		try {
			while ( microtime( true ) < $deadline ) {
				if ( $this->isCancelledOrReplaced( $jobId ) ) {
					return $this->status();
				}
				$stage = (string) ( $job['stage'] ?? '' );
				switch ( $stage ) {
					case self::STAGE_SAFE_PRIME:
						$job = $this->stageSafePrime( $job, $deadline );
						$this->store->put( JobSlots::KEYGEN, $job );
						if ( self::STAGE_SAFE_PRIME === ( $job['stage'] ?? '' ) ) {
							return $this->publicStatus( $job );
						}
						break;
					case self::STAGE_GENERATOR:
						$job = $this->stageGenerator( $job );
						$this->store->put( JobSlots::KEYGEN, $job );
						break;
					case self::STAGE_KEYPAIR:
						$job = $this->stageKeypair( $job );
						$this->store->put( JobSlots::KEYGEN, $job );
						break;
					case self::STAGE_PERSIST:
						$job = $this->stagePersist( $job );
						$this->store->put( JobSlots::KEYGEN, $job );
						break;
					case self::STAGE_SHAMIR:
						$job = $this->stageShamir( $job );
						$this->store->put( JobSlots::KEYGEN, $job );
						return $this->publicStatus( $job );
					case self::STAGE_COMPLETE:
						return $this->publicStatus( $job );
					default:
						throw new \RuntimeException( 'Estágio de keygen desconhecido: ' . $stage );
				}
				if ( self::STAGE_SAFE_PRIME !== $stage ) {
					break;
				}
			}
			$this->store->put( JobSlots::KEYGEN, $job );
			return $this->publicStatus( $job );
		} catch ( \Throwable $e ) {
			$job['stage']    = self::STAGE_FAILED;
			$job['progress'] = 0;
			$job['message']  = $e->getMessage();
			$job['error']    = $e->getMessage();
			$job['updated_at'] = time();
			$this->clearSecrets( $job );
			$this->store->put( JobSlots::KEYGEN, $job );
			return $this->publicStatus( $job );
		}
	}

	public function status(): array {
		$this->purgeExpired();
		return $this->publicStatus( $this->store->get( JobSlots::KEYGEN ) );
	}

	public function cancel(): array {
		$job = $this->store->get( JobSlots::KEYGEN );
		if ( null === $job ) {
			return $this->publicStatus( null );
		}
		$job['stage']      = self::STAGE_CANCELLED;
		$job['progress']   = 0;
		$job['message']    = 'Geração cancelada pelo administrador.';
		$job['updated_at'] = time();
		$this->clearSecrets( $job );
		$this->store->put( JobSlots::KEYGEN, $job );
		return $this->publicStatus( $job );
	}

	public function hasActive(): bool {
		$job = $this->store->get( JobSlots::KEYGEN );
		if ( null === $job ) {
			return false;
		}
		return in_array( (string) ( $job['stage'] ?? '' ), self::ACTIVE, true );
	}

	public function purgeExpired(): bool {
		$job = $this->store->get( JobSlots::KEYGEN );
		if ( null === $job ) {
			return false;
		}
		$updated = (int) ( $job['updated_at'] ?? $job['created_at'] ?? 0 );
		if ( $updated > 0 && ( time() - $updated ) > self::TTL_SECONDS ) {
			$this->clearSecrets( $job );
			$this->store->delete( JobSlots::KEYGEN );
			return true;
		}
		return false;
	}

	/** @param array<string,mixed> $job */
	public function markWorkerPid( int $pid ): void {
		$job = $this->store->get( JobSlots::KEYGEN );
		if ( null === $job || ! $this->hasActive() ) {
			return;
		}
		$job['worker_pid'] = $pid;
		$job['updated_at'] = time();
		$this->store->put( JobSlots::KEYGEN, $job );
	}

	/**
	 * @param array<string,mixed>|null $job
	 * @return array<string,mixed>
	 */
	private function publicStatus( ?array $job ): array {
		if ( null === $job ) {
			return array(
				'active'   => false,
				'stage'    => null,
				'progress' => 0,
				'message'  => '',
				'ok'       => true,
			);
		}
		$stage = (string) ( $job['stage'] ?? '' );
		return array(
			'active'        => in_array( $stage, self::ACTIVE, true ),
			'ok'            => self::STAGE_FAILED !== $stage,
			'job_id'        => (string) ( $job['job_id'] ?? '' ),
			'stage'         => $stage,
			'progress'      => (int) ( $job['progress'] ?? 0 ),
			'message'       => (string) ( $job['message'] ?? '' ),
			'bits'          => (int) ( $job['bits'] ?? 0 ),
			'attempts_done' => (int) ( $job['attempts_done'] ?? 0 ),
			'key_id'        => $job['key_id'] ?? null,
			'error'         => $job['error'] ?? null,
			'label'         => (string) ( $job['label'] ?? '' ),
			'updated_at'    => (int) ( $job['updated_at'] ?? 0 ),
			'created_at'    => (int) ( $job['created_at'] ?? 0 ),
			'worker_pid'    => $job['worker_pid'] ?? null,
		);
	}

	private function isCancelledOrReplaced( string $jobId ): bool {
		$stored = $this->store->get( JobSlots::KEYGEN );
		if ( ! is_array( $stored ) ) {
			return true;
		}
		if ( (string) ( $stored['job_id'] ?? '' ) !== $jobId ) {
			return true;
		}
		return self::STAGE_CANCELLED === ( $stored['stage'] ?? '' );
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function stageSafePrime( array $job, float $deadline ): array {
		$bits    = (int) $job['bits'];
		$attempt = (int) ( $job['safe_prime_attempt'] ?? 0 );
		$rng     = DeterministicRandom::fromHex( (string) $job['seed_hex'] );
		$qBits   = $bits - 1;

		while ( microtime( true ) < $deadline ) {
			$q = $rng->oddIntegerOfBitLengthForAttempt( $attempt, $qBits );
			++$attempt;
			$job['safe_prime_attempt'] = $attempt;
			$job['attempts_done']      = $attempt;
			$job['message']            = sprintf( 'Procurar primo seguro (tentativa %d, %d bits)…', $attempt, $bits );
			$job['progress']           = min( 50, 5 + (int) floor( log( max( 1, $attempt ), 2 ) ) );
			$job['updated_at']         = time();

			$found = PrimeGenerator::trySafePrimeFromQ( $q, $bits );
			if ( null === $found ) {
				continue;
			}
			list( $p, $qPrime ) = $found;
			$job['public_p'] = BigInt::toDecimalString( $p );
			$job['public_q'] = BigInt::toDecimalString( $qPrime );
			$job['stage']    = self::STAGE_GENERATOR;
			$job['message']  = 'Primo seguro encontrado. Selecionar gerador…';
			$job['progress'] = 55;
			$job['updated_at'] = time();
			return $job;
		}
		return $job;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function stageGenerator( array $job ): array {
		$p = BigInt::fromDecimalString( (string) $job['public_p'] );
		$q = BigInt::fromDecimalString( (string) $job['public_q'] );
		$g = PrimeGenerator::findGeneratorForSafePrime( $p, $q );
		$job['public_g']   = BigInt::toDecimalString( $g );
		$job['stage']      = self::STAGE_KEYPAIR;
		$job['message']    = 'Gerador selecionado. Criar par de chaves…';
		$job['progress']   = 65;
		$job['updated_at'] = time();
		return $job;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function stageKeypair( array $job ): array {
		$p    = BigInt::fromDecimalString( (string) $job['public_p'] );
		$q    = BigInt::fromDecimalString( (string) $job['public_q'] );
		$g    = BigInt::fromDecimalString( (string) $job['public_g'] );
		$bits = (int) $job['bits'];
		$kp   = ElGamal::generateKeyPairFromParams( $p, $q, $g, $bits );
		$job['public_y']   = $kp->getY();
		$job['private_x']  = $kp->getX();
		$job['stage']      = self::STAGE_PERSIST;
		$job['message']    = 'Par ElGamal criado. Gravar chave pública…';
		$job['progress']   = 75;
		$job['updated_at'] = time();
		$kp->clearPrivateExponent();
		return $job;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function stagePersist( array $job ): array {
		$keyId = $this->persistence->keys->create(
			array(
				'key_label'    => (string) ( $job['key_label'] ?? $job['label'] ?? '' ),
				'display_name' => (string) ( $job['display_name'] ?? $job['label'] ?? '' ),
				'key_size'     => (int) $job['bits'],
				'public_p'     => (string) $job['public_p'],
				'public_q'     => (string) $job['public_q'],
				'public_g'     => (string) $job['public_g'],
				'public_y'     => (string) $job['public_y'],
				'threshold'    => (int) $job['threshold_t'],
				'total_shares' => (int) $job['total_n'],
				'field_prime'  => '',
			)
		);
		$job['key_id']     = $keyId;
		$job['stage']      = self::STAGE_SHAMIR;
		$job['message']    = 'Chave pública gravada. Dividir parcelas Shamir…';
		$job['progress']   = 85;
		$job['updated_at'] = time();
		return $job;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function stageShamir( array $job ): array {
		$keyId = (int) $job['key_id'];
		$th    = (int) $job['threshold_t'];
		$n     = (int) $job['total_n'];
		$okIds = array_values( array_map( 'intval', $job['officials'] ?? array() ) );
		$x     = BigInt::fromDecimalString( (string) $job['private_x'] );
		$field = PrimeGenerator::generatePrimeGreaterThan( $x, 64 );
		$shares = ShamirSecretSharing::splitSecret( $x, $th, $n, $field );
		$fieldPrimeStr = BigInt::toDecimalString( $field );
		$pub = array(
			'p' => (string) $job['public_p'],
			'q' => (string) $job['public_q'],
			'g' => (string) $job['public_g'],
			'y' => (string) $job['public_y'],
		);

		// Atualizar field_prime na chave.
		$this->persistence->keys->updateThresholdMeta( $keyId, $fieldPrimeStr, $th, $n );

		$courier = new MaterialCourier( $this->courierDir );
		foreach ( $shares as $i => $point ) {
			$idx = (int) ( $point['x'] ?? ( $i + 1 ) );
			$uid = $okIds[ $i ] ?? 0;
			$payload = ShamirSecretSharing::buildSharePayload(
				$keyId,
				0,
				$th,
				$n,
				$field,
				$idx,
				$point['y'],
				$pub
			);
			$this->persistence->shares->create(
				array(
					'key_id'           => $keyId,
					'official_user_id' => $uid,
					'share_index'      => $idx,
					'share_payload'    => $payload,
					'threshold_t'      => $th,
					'total_n'          => $n,
					'field_prime'      => $fieldPrimeStr,
					'status'           => 'assigned',
				)
			);
			$courier->writeJson(
				'parcela-' . $idx . '.json',
				array_merge(
					$payload,
					array(
						'official_user_id' => $uid,
					)
				)
			);
		}

		$title = (string) ( $job['display_name'] ?? $job['label'] ?? '' );
		$pkg   = PublicKeyPackage::build(
			array(
				'key_label'   => $title,
				'key_size'    => (int) $job['bits'],
				'p'           => (string) $job['public_p'],
				'q'           => (string) $job['public_q'],
				'g'           => (string) $job['public_g'],
				'y'           => (string) $job['public_y'],
				'field_prime' => $fieldPrimeStr,
				'threshold_t' => $th,
				'total_n'     => $n,
				'source_mode' => SiteModes::KEY_AUTHORITY,
				'cliente_id'  => $this->clienteId,
				'cliente_nome'=> $this->clienteId,
			)
		);
		$courier->writeJson( 'public-key.json', $pkg );

		$this->clearSecrets( $job );
		$job['field_prime'] = $fieldPrimeStr;
		$job['stage']       = self::STAGE_COMPLETE;
		$job['progress']    = 100;
		$job['message']     = sprintf(
			'Chave «%s» (#%d, %d bits) concluída; %d parcelas atribuídas. Pode sair e voltar — o trabalho já terminou.',
			$title,
			$keyId,
			(int) $job['bits'],
			$n
		);
		$job['error']       = null;
		$job['updated_at']  = time();
		return $job;
	}

	/** @param array<string,mixed> $job */
	private function clearSecrets( array &$job ): void {
		$job['private_x'] = null;
		$job['seed_hex']  = '';
	}
}
