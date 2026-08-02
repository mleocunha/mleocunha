<?php
/**
 * Deterministic RNG for resumable cryptographic searches.
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-SHA256 counter DRBG for checkpointed prime search.
 *
 * Not for long-term secret generation entropy source selection by itself —
 * the seed must come from CryptoRandom::randomBytes().
 */
class DeterministicRandom {

	/**
	 * Seed (binary).
	 *
	 * @var string
	 */
	private string $seed;

	/**
	 * Counter for stream expansion.
	 *
	 * @var int
	 */
	private int $counter = 0;

	/**
	 * Constructor.
	 *
	 * @param string $seed_binary Binary seed (at least 16 bytes recommended).
	 */
	public function __construct( string $seed_binary ) {
		if ( strlen( $seed_binary ) < 16 ) {
			throw new CryptoException( __( 'Deterministic RNG seed too short.', 'relatasoft-secure-election-suite' ) );
		}
		$this->seed = $seed_binary;
	}

	/**
	 * Create from hex seed.
	 *
	 * @param string $seed_hex Hex-encoded seed.
	 * @return self
	 */
	public static function fromHex( string $seed_hex ): self {
		$rses_bin = hex2bin( $seed_hex );
		if ( false === $rses_bin ) {
			throw new CryptoException( __( 'Invalid hex seed.', 'relatasoft-secure-election-suite' ) );
		}
		return new self( $rses_bin );
	}

	/**
	 * Create with a fresh CSPRNG seed.
	 *
	 * @return array{rng:self,seed_hex:string}
	 */
	public static function createFresh(): array {
		$rses_seed = CryptoRandom::randomBytes( 32 );
		return array(
			'rng'      => new self( $rses_seed ),
			'seed_hex' => bin2hex( $rses_seed ),
		);
	}

	/**
	 * Seek stream to a logical attempt index (legacy counter DRBG).
	 *
	 * Prefer {@see self::oddIntegerOfBitLengthForAttempt()} for resumable searches —
	 * that API derives each attempt solely from (seed, attempt) and cannot desync.
	 *
	 * @param int $attempt           Attempt index (>= 0).
	 * @param int $bytes_per_attempt Bytes consumed per attempt (default 1024).
	 */
	public function seekAttempt( int $attempt, int $bytes_per_attempt = 1024 ): void {
		$rses_blocks   = max( 1, (int) ceil( max( 1, $bytes_per_attempt ) / 32 ) );
		$this->counter = max( 0, $attempt ) * $rses_blocks;
	}

	/**
	 * Generate random bytes.
	 *
	 * @param int $length Length.
	 * @return string
	 */
	public function randomBytes( int $length ): string {
		if ( $length < 1 ) {
			throw new CryptoException( __( 'Random byte length must be positive.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_out = '';
		while ( strlen( $rses_out ) < $length ) {
			$rses_block  = hash_hmac( 'sha256', $this->seed . pack( 'N', $this->counter ), $this->seed, true );
			++$this->counter;
			$rses_out .= $rses_block;
		}

		return substr( $rses_out, 0, $length );
	}

	/**
	 * Bytes for a single search attempt (independent of prior counter state).
	 *
	 * Same seed + attempt + length always yields the same bytes, so a mid-search
	 * checkpoint can resume the exact same attempt stream after a process restart.
	 *
	 * @param int $attempt Attempt index (>= 0).
	 * @param int $length  Length.
	 * @return string
	 */
	public function randomBytesForAttempt( int $attempt, int $length ): string {
		if ( $length < 1 ) {
			throw new CryptoException( __( 'Random byte length must be positive.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_out   = '';
		$rses_block = 0;
		$rses_label = 'rses-attempt';
		while ( strlen( $rses_out ) < $length ) {
			$rses_out .= hash_hmac(
				'sha256',
				$this->seed . $rses_label . pack( 'NN', max( 0, $attempt ), $rses_block ),
				$this->seed,
				true
			);
			++$rses_block;
		}

		return substr( $rses_out, 0, $length );
	}

	/**
	 * Random integer in [min, max] via rejection sampling.
	 *
	 * @param \GMP $min_inclusive Min.
	 * @param \GMP $max_inclusive Max.
	 * @return \GMP
	 */
	public function randomIntegerBetween( \GMP $min_inclusive, \GMP $max_inclusive ): \GMP {
		if ( \gmp_cmp( $min_inclusive, $max_inclusive ) > 0 ) {
			throw new CryptoException( __( 'Invalid random range: min exceeds max.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_range       = \gmp_add( \gmp_sub( $max_inclusive, $min_inclusive ), \gmp_init( 1 ) );
		$rses_bit_length  = BigInt::bitLength( $rses_range );
		$rses_byte_length = (int) ceil( $rses_bit_length / 8 );
		if ( $rses_byte_length < 1 ) {
			$rses_byte_length = 1;
		}

		for ( $rses_i = 0; $rses_i < 10000; ++$rses_i ) {
			$rses_bytes     = $this->randomBytes( $rses_byte_length );
			$rses_candidate = \gmp_init( bin2hex( $rses_bytes ), 16 );
			if ( \gmp_cmp( $rses_candidate, $rses_range ) < 0 ) {
				return \gmp_add( $rses_candidate, $min_inclusive );
			}
		}

		throw new CryptoException( __( 'Failed to generate deterministic random integer.', 'relatasoft-secure-election-suite' ) );
	}

	/**
	 * Odd integer of exact bit length for prime candidates.
	 *
	 * @param int $bits Bit length.
	 * @return \GMP
	 */
	public function oddIntegerOfBitLength( int $bits ): \GMP {
		if ( $bits < 2 ) {
			throw new CryptoException( __( 'Bit length must be at least 2.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_byte_length = (int) ceil( $bits / 8 );
		return self::rses_shape_odd_bit_length( $this->randomBytes( $rses_byte_length ), $bits );
	}

	/**
	 * Odd integer for a specific attempt index (resumable safe-prime search).
	 *
	 * @param int $attempt Attempt index (>= 0).
	 * @param int $bits    Bit length.
	 * @return \GMP
	 */
	public function oddIntegerOfBitLengthForAttempt( int $attempt, int $bits ): \GMP {
		if ( $bits < 2 ) {
			throw new CryptoException( __( 'Bit length must be at least 2.', 'relatasoft-secure-election-suite' ) );
		}

		$rses_byte_length = (int) ceil( $bits / 8 );
		return self::rses_shape_odd_bit_length( $this->randomBytesForAttempt( $attempt, $rses_byte_length ), $bits );
	}

	/**
	 * Shape raw bytes into an odd integer of exact bit length.
	 *
	 * @param string $bytes Raw bytes.
	 * @param int    $bits  Bit length.
	 * @return \GMP
	 */
	private static function rses_shape_odd_bit_length( string $bytes, int $bits ): \GMP {
		$rses_candidate = \gmp_init( bin2hex( $bytes ), 16 );

		$rses_max = \gmp_sub( \gmp_pow( \gmp_init( 2 ), $bits ), \gmp_init( 1 ) );
		$rses_min = \gmp_pow( \gmp_init( 2 ), $bits - 1 );

		$rses_candidate = \gmp_mod( $rses_candidate, \gmp_add( $rses_max, \gmp_init( 1 ) ) );
		if ( \gmp_cmp( $rses_candidate, $rses_min ) < 0 ) {
			$rses_candidate = \gmp_add( $rses_candidate, $rses_min );
		}
		if ( \gmp_cmp( $rses_candidate, $rses_max ) > 0 ) {
			$rses_candidate = \gmp_sub( $rses_candidate, $rses_min );
			$rses_candidate = \gmp_add( $rses_candidate, $rses_min );
		}
		if ( 0 === \gmp_cmp( \gmp_mod( $rses_candidate, \gmp_init( 2 ) ), \gmp_init( 0 ) ) ) {
			$rses_candidate = \gmp_add( $rses_candidate, \gmp_init( 1 ) );
		}
		if ( \gmp_cmp( $rses_candidate, $rses_max ) > 0 ) {
			$rses_candidate = \gmp_sub( $rses_candidate, \gmp_init( 2 ) );
		}

		return $rses_candidate;
	}
}
