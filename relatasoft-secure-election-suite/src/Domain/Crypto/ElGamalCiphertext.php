<?php
declare(strict_types=1);
/**
 * ElGamal ciphertext value object.
 *
 * Portable Domain crypto (A1) — no WordPress runtime required.
 *
 * @package RelataSoft\SecureElectionSuite\Painel\Domain\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Crypto;


/**
 * ElGamal ciphertext (alpha, beta).
 */
class ElGamalCiphertext {

	/**
	 * Alpha component.
	 *
	 * @var \GMP
	 */
	private \GMP $alpha;

	/**
	 * Beta component.
	 *
	 * @var \GMP
	 */
	private \GMP $beta;

	/**
	 * Constructor.
	 *
	 * @param \GMP $alpha Alpha.
	 * @param \GMP $beta  Beta.
	 */
	public function __construct( \GMP $alpha, \GMP $beta ) {
		$this->alpha = $alpha;
		$this->beta  = $beta;
	}

	/**
	 * Get alpha.
	 *
	 * @return \GMP
	 */
	public function getAlpha(): \GMP {
		return $this->alpha;
	}

	/**
	 * Get beta.
	 *
	 * @return \GMP
	 */
	public function getBeta(): \GMP {
		return $this->beta;
	}

	/**
	 * Convert to decimal string array.
	 *
	 * @return array{alpha:string,beta:string}
	 */
	public function toDecimalArray(): array {
		return array(
			'alpha' => BigInt::toDecimalString( $this->alpha ),
			'beta'  => BigInt::toDecimalString( $this->beta ),
		);
	}

	/**
	 * Create from decimal strings.
	 *
	 * @param string $alpha Alpha decimal string.
	 * @param string $beta  Beta decimal string.
	 * @return self
	 */
	public static function fromDecimalStrings( string $alpha, string $beta ): self {
		return new self(
			BigInt::fromDecimalString( $alpha ),
			BigInt::fromDecimalString( $beta )
		);
	}
}
