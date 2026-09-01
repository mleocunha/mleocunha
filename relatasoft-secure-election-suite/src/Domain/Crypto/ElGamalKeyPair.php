<?php
declare(strict_types=1);
/**
 * ElGamal key pair value object.
 *
 * Portable Domain crypto (A1) — no WordPress runtime required.
 *
 * @package RelataSoft\SecureElectionSuite\Painel\Domain\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Crypto;


/**
 * ElGamal key pair with public and private components.
 */
class ElGamalKeyPair {

	/**
	 * Constructor.
	 *
	 * @param string $p            Prime p decimal string.
	 * @param string $q            Subgroup order q decimal string.
	 * @param string $g            Generator g decimal string.
	 * @param string $x            Private exponent decimal string.
	 * @param string $y            Public component decimal string.
	 * @param int    $key_size_bits Key size in bits.
	 * @param string $created_at   Creation timestamp.
	 */
	public function __construct(
		private string $p,
		private string $q,
		private string $g,
		private string $x,
		private string $y,
		private int $key_size_bits,
		private string $created_at
	) {
	}

	/**
	 * Get p.
	 *
	 * @return string
	 */
	public function getP(): string {
		return $this->p;
	}

	/**
	 * Get q.
	 *
	 * @return string
	 */
	public function getQ(): string {
		return $this->q;
	}

	/**
	 * Get g.
	 *
	 * @return string
	 */
	public function getG(): string {
		return $this->g;
	}

	/**
	 * Get private exponent x.
	 *
	 * @return string
	 */
	public function getX(): string {
		return $this->x;
	}

	/**
	 * Get public component y.
	 *
	 * @return string
	 */
	public function getY(): string {
		return $this->y;
	}

	/**
	 * Get key size bits.
	 *
	 * @return int
	 */
	public function getKeySizeBits(): int {
		return $this->key_size_bits;
	}

	/**
	 * Get created at.
	 *
	 * @return string
	 */
	public function getCreatedAt(): string {
		return $this->created_at;
	}

	/**
	 * Get public key as array.
	 *
	 * @return array<string,string|int>
	 */
	public function getPublicKeyArray(): array {
		return array(
			'p'            => $this->p,
			'q'            => $this->q,
			'g'            => $this->g,
			'y'            => $this->y,
			'keySizeBits'  => $this->key_size_bits,
			'createdAt'    => $this->created_at,
		);
	}

	/**
	 * Get full key pair as array (includes private x).
	 *
	 * @return array<string,string|int>
	 */
	public function getFullKeyArray(): array {
		return array_merge(
			$this->getPublicKeyArray(),
			array( 'x' => $this->x )
		);
	}

	/**
	 * Get GMP public key components.
	 *
	 * @return array{p:\GMP,q:\GMP,g:\GMP,y:\GMP}
	 */
	public function getPublicGmp(): array {
		return array(
			'p' => BigInt::fromDecimalString( $this->p ),
			'q' => BigInt::fromDecimalString( $this->q ),
			'g' => BigInt::fromDecimalString( $this->g ),
			'y' => BigInt::fromDecimalString( $this->y ),
		);
	}

	/**
	 * Get private exponent as GMP.
	 *
	 * @return \GMP
	 */
	public function getPrivateGmp(): \GMP {
		return BigInt::fromDecimalString( $this->x );
	}

	/**
	 * Apagar o expoente privado desta instância (não persistir em memória além do necessário).
	 *
	 * Após a divisão Shamir, chamar isto antes de libertar a referência ao par.
	 */
	public function clearPrivateExponent(): void {
		$this->x = '0';
	}

	/** O expoente privado ainda está presente (não foi limpo)? */
	public function hasPrivateExponent(): bool {
		return '' !== $this->x && '0' !== $this->x;
	}
}
