<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Application\Standalone;

use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\NodeRuntime;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\BigInt;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ElGamal;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ElGamalCiphertext;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\HomomorphicTally;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\PrimeGenerator;
use RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ShamirSecretSharing;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\MaterialCourier;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\PublicKeyPackage;
use RelataSoft\SecureElectionSuite\Painel\Domain\Material\VoteMaterialPackage;

/**
 * Pilot orchestration for Adapter #2 — three isolated nodes, manual courier only.
 *
 * Does not sync databases. Material moves only via {@see MaterialCourier} files.
 */
final class ThreeNodePilot {

	public const PUBLIC_KEY_FILE   = 'public-key.json';
	public const VOTE_MATERIAL_FILE = 'vote-material.json';
	public const PARCEL_PREFIX     = 'parcela-';

	public function __construct(
		public readonly NodeRuntime $keyAuthority,
		public readonly NodeRuntime $voting,
		public readonly NodeRuntime $tallying,
		public readonly MaterialCourier $courier,
		private readonly int $bits = 512,
		private readonly int $threshold = 2,
		private readonly int $totalShares = 3,
	) {
		$this->keyAuthority->requireMode( SiteModes::KEY_AUTHORITY );
		$this->voting->requireMode( SiteModes::VOTING );
		$this->tallying->requireMode( SiteModes::TALLYING );
	}

	/**
	 * Spin three nodes under $root/{ka,voting,tallying} + courier/.
	 *
	 * @param bool $durable Persistence JSON per node (default true — Adapter #2 hardening).
	 */
	public static function createWorkspace( string $root, string $clienteId = 'piloto', int $bits = 512, bool $durable = true ): self {
		$ka = NodeRuntime::create( SiteModes::KEY_AUTHORITY, $root . '/ka', $clienteId, $durable );
		$vt = NodeRuntime::create( SiteModes::VOTING, $root . '/voting', $clienteId, $durable );
		$tl = NodeRuntime::create( SiteModes::TALLYING, $root . '/tallying', $clienteId, $durable );
		$courier = new MaterialCourier( $root . '/courier' );
		return new self( $ka, $vt, $tl, $courier, $bits );
	}

	/**
	 * Full E3 pilot: keygen → handoff public key + parcels → cast → reconstruct → certify.
	 *
	 * @return array{
	 *   key_id:int,
	 *   election_id:int,
	 *   round_id:int,
	 *   tally:int,
	 *   certification_id:int,
	 *   courier_files:list<string>
	 * }
	 */
	public function run( int $yesVotes = 2 ): array {
		$kaResult = $this->runKeyAuthority();
		$this->runVotingImportAndCast( $kaResult['public_package'], $yesVotes );
		$cert = $this->runTallying( $kaResult['public_package'], $kaResult['field_prime'], $yesVotes );

		return array(
			'key_id'            => $kaResult['key_id'],
			'election_id'       => $cert['election_id'],
			'round_id'          => $cert['round_id'],
			'tally'             => $cert['tally'],
			'certification_id'  => $cert['certification_id'],
			'courier_files'     => $this->listCourierBasenames(),
		);
	}

	/**
	 * @return array{key_id:int,public_package:array<string,mixed>,field_prime:string,private_x:string}
	 */
	public function runKeyAuthority(): array {
		$this->keyAuthority->requireMode( SiteModes::KEY_AUTHORITY );

		$keypair = ElGamal::generateKeyPair( $this->bits );
		$x       = $keypair->getPrivateGmp();
		$field   = PrimeGenerator::generatePrimeGreaterThan( $x, 64 );
		$shares  = ShamirSecretSharing::splitSecret( $x, $this->threshold, $this->totalShares, $field );

		$keyId = $this->keyAuthority->persistence->keys->create(
			array(
				'key_label'    => 'piloto-a6',
				'public_p'     => $keypair->getP(),
				'public_q'     => $keypair->getQ(),
				'public_g'     => $keypair->getG(),
				'public_y'     => $keypair->getY(),
				'private_x'    => $keypair->getX(), // stays ONLY on KA node store
				'key_size'     => $keypair->getKeySizeBits(),
				'is_deleted'   => 0,
			)
		);
		$this->keyAuthority->persistence->keys->updateThresholdMeta(
			$keyId,
			BigInt::toDecimalString( $field ),
			$this->threshold,
			$this->totalShares
		);

		$pub = array(
			'p' => $keypair->getP(),
			'q' => $keypair->getQ(),
			'g' => $keypair->getG(),
			'y' => $keypair->getY(),
		);

		foreach ( $shares as $i => $point ) {
			$payload = ShamirSecretSharing::buildSharePayload(
				$keyId,
				0,
				$this->threshold,
				$this->totalShares,
				$field,
				(int) $point['x'],
				$point['y'],
				$pub
			);
			$this->keyAuthority->persistence->shares->create(
				array(
					'key_id'           => $keyId,
					'official_user_id' => $i + 1,
					'share_index'      => (int) $point['x'],
					'share_payload'    => $payload,
				)
			);
			// Offline parcels — courier only; never written into voting DB.
			$this->courier->writeJson( self::PARCEL_PREFIX . $point['x'] . '.json', $payload );
		}

		$package = PublicKeyPackage::build(
			array(
				'key_label'   => 'piloto-a6',
				'key_size'    => $keypair->getKeySizeBits(),
				'p'           => $keypair->getP(),
				'q'           => $keypair->getQ(),
				'g'           => $keypair->getG(),
				'y'           => $keypair->getY(),
				'field_prime' => BigInt::toDecimalString( $field ),
				'threshold_t' => $this->threshold,
				'total_n'     => $this->totalShares,
				'source_mode' => SiteModes::KEY_AUTHORITY,
				'cliente_id'  => $this->keyAuthority->clienteId,
				'cliente_nome'=> $this->keyAuthority->clienteId,
			)
		);
		$this->courier->writeJson( self::PUBLIC_KEY_FILE, $package );

		$this->keyAuthority->persistence->auditLog->append(
			array(
				'action'        => 'piloto.keygen',
				'object_type'   => 'standalone',
				'current_hash'  => hash( 'sha256', 'piloto.keygen.' . $keyId ),
				'previous_hash' => $this->keyAuthority->persistence->auditLog->lastHash(),
			)
		);

		return array(
			'key_id'          => $keyId,
			'public_package'  => $package,
			'field_prime'     => BigInt::toDecimalString( $field ),
			'private_x'       => $keypair->getX(),
		);
	}

	/**
	 * @param array<string,mixed> $publicPackage
	 * @return array{election_id:int,round_id:int,ballots:int}
	 */
	public function runVotingImportAndCast( array $publicPackage, int $yesVotes ): array {
		$this->voting->requireMode( SiteModes::VOTING );

		$v = PublicKeyPackage::validate( $publicPackage );
		if ( empty( $v['ok'] ) ) {
			throw new \RuntimeException( 'Invalid public key package: ' . ( $v['error'] ?? '?' ) );
		}

		// Isolation check: voting must not already see KA private material.
		if ( count( $this->voting->persistence->keys->listActive() ) > 0 ) {
			throw new \RuntimeException( 'Voting node already has keys — expected empty isolated store.' );
		}

		$pk = $publicPackage['public_key'];
		$keyId = $this->voting->persistence->keys->create(
			array(
				'key_label'  => (string) ( $publicPackage['key_label'] ?? 'imported' ),
				'public_p'   => (string) $pk['p'],
				'public_q'   => (string) $pk['q'],
				'public_g'   => (string) $pk['g'],
				'public_y'   => (string) $pk['y'],
				'key_size'   => (int) ( $publicPackage['key_size'] ?? 512 ),
				'is_deleted' => 0,
			)
		);
		// Never store private_x on voting node.
		$row = $this->voting->persistence->keys->find( $keyId );
		if ( isset( $row['private_x'] ) && '' !== (string) $row['private_x'] ) {
			throw new \RuntimeException( 'Private key leaked onto voting node.' );
		}

		$electionId = $this->voting->persistence->elections->createElection(
			array(
				'title'         => 'Piloto A6',
				'voting_method' => 'approval',
				'status'        => 'open',
			)
		);
		$roundId = $this->voting->persistence->elections->createRound(
			array(
				'election_id'  => $electionId,
				'round_number' => 1,
				'title'        => 'Turno 1',
				'status'       => 'open',
			)
		);
		$qid = $this->voting->persistence->elections->createQuestion(
			array(
				'election_id'    => $electionId,
				'round_id'       => $roundId,
				'question_title' => 'Aprovar?',
				'question_type'  => 'yes_no',
				'min_choices'    => 1,
				'max_choices'    => 1,
				'order_index'    => 0,
			)
		);

		$p = BigInt::fromDecimalString( (string) $pk['p'] );
		$q = BigInt::fromDecimalString( (string) $pk['q'] );
		$g = BigInt::fromDecimalString( (string) $pk['g'] );
		$y = BigInt::fromDecimalString( (string) $pk['y'] );

		$ballots = array();
		for ( $i = 1; $i <= $yesVotes; ++$i ) {
			$ct    = HomomorphicTally::encryptCount( 1, $p, $q, $g, $y );
			$alpha = BigInt::toDecimalString( $ct->getAlpha() );
			$beta  = BigInt::toDecimalString( $ct->getBeta() );
			$hash  = hash( 'sha256', $alpha . '|' . $beta . '|' . $i );
			$this->voting->persistence->votes->store(
				array(
					'voter_user_id'    => $i,
					'round_id'         => $roundId,
					'question_id'      => $qid,
					'election_id'      => $electionId,
					'ciphertext_alpha' => $alpha,
					'ciphertext_beta'  => $beta,
					'vote_hash'        => $hash,
					'cast_at'          => gmdate( 'c' ),
				)
			);
			$ballots[] = array(
				'voter_id'    => $i,
				'question_id' => $qid,
				'alpha'       => $alpha,
				'beta'        => $beta,
				'receipt'     => $hash,
			);
		}

		$material = VoteMaterialPackage::build(
			array(
				'election_id'         => $electionId,
				'round_id'            => $roundId,
				'public_key_checksum' => (string) ( $publicPackage['checksum'] ?? '' ),
				'ballots'             => $ballots,
				'source_mode'         => SiteModes::VOTING,
				'cliente_id'          => $this->voting->clienteId,
				'cliente_nome'        => $this->voting->clienteId,
			)
		);
		$this->courier->writeJson( self::VOTE_MATERIAL_FILE, $material );

		return array(
			'election_id' => $electionId,
			'round_id'    => $roundId,
			'ballots'     => count( $ballots ),
		);
	}

	/**
	 * @param array<string,mixed> $publicPackage
	 * @return array{election_id:int,round_id:int,tally:int,certification_id:int}
	 */
	public function runTallying( array $publicPackage, string $fieldPrime, int $expectedTally ): array {
		$this->tallying->requireMode( SiteModes::TALLYING );

		$votesRaw = $this->courier->readJson( self::VOTE_MATERIAL_FILE );
		$votesOk  = VoteMaterialPackage::validate( $votesRaw );
		if ( empty( $votesOk['ok'] ) ) {
			throw new \RuntimeException( 'Invalid vote material package: ' . ( $votesOk['error'] ?? '?' ) );
		}
		$votesPkg = $votesRaw;

		// Collect threshold parcels from courier (manual offline shares).
		$sharePoints = array();
		for ( $i = 1; $i <= $this->threshold; ++$i ) {
			$parcel = $this->courier->readJson( self::PARCEL_PREFIX . $i . '.json' );
			$sharePoints[] = array(
				'x' => (int) $parcel['share_index'],
				'y' => BigInt::fromDecimalString( (string) $parcel['share_value'] ),
			);
		}

		$field = BigInt::fromDecimalString( $fieldPrime );
		$x     = ShamirSecretSharing::reconstructWithThreshold( $sharePoints, $field, $this->threshold );

		$pk = $publicPackage['public_key'];
		$p  = BigInt::fromDecimalString( (string) $pk['p'] );
		$q  = BigInt::fromDecimalString( (string) $pk['q'] );
		$g  = BigInt::fromDecimalString( (string) $pk['g'] );

		// Verify reconstructed x matches public y (without reading KA private store).
		$yCheck = BigInt::modPow( $g, $x, $p );
		if ( 0 !== \gmp_cmp( $yCheck, BigInt::fromDecimalString( (string) $pk['y'] ) ) ) {
			throw new \RuntimeException( 'Reconstructed secret does not match public key.' );
		}

		$ciphertexts = array();
		foreach ( $votesPkg['ballots'] as $ballot ) {
			$ciphertexts[] = new ElGamalCiphertext(
				BigInt::fromDecimalString( (string) $ballot['alpha'] ),
				BigInt::fromDecimalString( (string) $ballot['beta'] )
			);
		}
		$sum   = HomomorphicTally::aggregateCounts( $ciphertexts, $p );
		$tally = HomomorphicTally::decryptAndDecode( $sum, $p, $q, $g, $x, max( 10, $expectedTally + 5 ) );

		if ( $tally !== $expectedTally ) {
			throw new \RuntimeException( sprintf( 'Tally mismatch: got %d expected %d.', $tally, $expectedTally ) );
		}

		$importId = $this->tallying->persistence->tallyImports->create(
			array(
				'import_manifest_json' => json_encode(
					array(
						'source'       => 'piloto-courier',
						'round_id'     => (int) $votesPkg['round_id'],
						'election_id'  => (int) $votesPkg['election_id'],
						'ballot_count' => count( $votesPkg['ballots'] ),
					)
				),
				'import_hash' => hash( 'sha256', (string) ( $votesPkg['checksum'] ?? '' ) ),
				'status'      => 'ready',
			)
		);

		$certId = $this->tallying->persistence->certifications->create(
			array(
				'tally_import_id'          => $importId,
				'certification_status'     => 'certified',
				'verification_report_json' => json_encode(
					array(
						'tally'       => $tally,
						'election_id' => (int) $votesPkg['election_id'],
						'round_id'    => (int) $votesPkg['round_id'],
					)
				),
			)
		);

		$this->tallying->persistence->auditLog->append(
			array(
				'action'        => 'piloto.certified',
				'object_type'   => 'standalone',
				'current_hash'  => hash( 'sha256', 'piloto.certified.' . $certId . '.' . $tally ),
				'previous_hash' => $this->tallying->persistence->auditLog->lastHash(),
			)
		);

		return array(
			'election_id'      => (int) $votesPkg['election_id'],
			'round_id'         => (int) $votesPkg['round_id'],
			'tally'            => $tally,
			'certification_id' => $certId,
		);
	}

	/**
	 * Prove stores are not shared (no automatic sync).
	 *
	 * @return array{ka_keys:int,voting_keys:int,tallying_keys:int}
	 */
	public function isolationSnapshot(): array {
		return array(
			'ka_keys'       => count( $this->keyAuthority->persistence->keys->listActive() ),
			'voting_keys'   => count( $this->voting->persistence->keys->listActive() ),
			'tallying_keys' => count( $this->tallying->persistence->keys->listActive() ),
		);
	}

	/**
	 * @return list<string>
	 */
	private function listCourierBasenames(): array {
		$files = glob( $this->courier->directory() . '/*.json' ) ?: array();
		$out   = array();
		foreach ( $files as $f ) {
			$out[] = basename( $f );
		}
		sort( $out );
		return $out;
	}
}
