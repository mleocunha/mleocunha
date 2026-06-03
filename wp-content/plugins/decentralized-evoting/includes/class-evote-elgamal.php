<?php
/**
 * Modular ElGamal over a safe prime subgroup (RFC 3526 Group 14).
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use phpseclib3\Math\BigInteger;

/**
 * Key generation, encrypt, and decrypt in the multiplicative group mod p.
 */
class EVote_Elgamal {

	const SCHEME = 'modular-elgamal';

	/** @var BigInteger */
	private $p;

	/** @var BigInteger Subgroup order q = (p-1)/2. */
	private $q;

	/** @var BigInteger Generator of order q. */
	private $g;

	/**
	 * @param BigInteger $p Safe prime.
	 * @param BigInteger $g Generator (subgroup order q).
	 */
	public function __construct( BigInteger $p, BigInteger $g ) {
		$this->p = $p;
		$two     = new BigInteger( '2' );
		$this->q = $p->subtract( $two )->divide( $two )[0];
		$this->g = $this->normalize_generator( $g );
	}

	/**
	 * Standard 2048-bit MODP group.
	 *
	 * @return self
	 */
	public static function from_rfc3526_group14() {
		require_once EVOTE_PLUGIN_DIR . 'includes/data/rfc3526-group14.php';
		$params = evote_rfc3526_group14();
		$p      = self::from_hex( $params['p'] );
		$g      = self::from_hex( $params['g'] );
		return new self( $p, $g );
	}

	/**
	 * @return array{p: BigInteger, q: BigInteger, g: BigInteger}
	 */
	public function get_parameters() {
		return array(
			'p' => $this->p,
			'q' => $this->q,
			'g' => $this->g,
		);
	}

	/**
	 * Generate ElGamal key pair; private key lives in Z_q*.
	 *
	 * @return array{private: BigInteger, public: BigInteger}|WP_Error
	 */
	public function generate_keypair() {
		$one = new BigInteger( '1' );
		$x   = self::random_range( $this->q );
		if ( $x->compare( $one ) < 0 ) {
			return new WP_Error( 'evote_keygen_failed', __( 'Failed to sample private key.', 'decentralized-evoting' ) );
		}
		$y = $this->g->modPow( $x, $this->p );
		return array(
			'private' => $x,
			'public'  => $y,
		);
	}

	/**
	 * Encrypt message m (must satisfy 1 < m < p).
	 *
	 * @param BigInteger $m Message.
	 * @param BigInteger $y Public key.
	 * @return array{c1: BigInteger, c2: BigInteger}|WP_Error
	 */
	public function encrypt( BigInteger $m, BigInteger $y ) {
		$m = $m->mod( $this->p );
		if ( $m->equals( new BigInteger( '0' ) ) || $m->compare( $this->p ) >= 0 ) {
			return new WP_Error( 'evote_invalid_message', __( 'Message must be in Z_p*.', 'decentralized-evoting' ) );
		}

		$k  = self::random_range( $this->q );
		$c1 = $this->g->modPow( $k, $this->p );
		$c2 = $m->multiply( $y->modPow( $k, $this->p ) )->mod( $this->p );

		return array(
			'c1' => $c1,
			'c2' => $c2,
		);
	}

	/**
	 * Decrypt ciphertext.
	 *
	 * @param BigInteger $c1 Component 1.
	 * @param BigInteger $c2 Component 2.
	 * @param BigInteger $x  Private key.
	 * @return BigInteger|WP_Error
	 */
	public function decrypt( BigInteger $c1, BigInteger $c2, BigInteger $x ) {
		$s = $c1->modPow( $x, $this->p );
		$inv = $s->modInverse( $this->p );
		if ( false === $inv ) {
			return new WP_Error( 'evote_decrypt_failed', __( 'Could not invert shared secret component.', 'decentralized-evoting' ) );
		}
		return $c2->multiply( $inv )->mod( $this->p );
	}

	/**
	 * Exponential ElGamal encrypt: plaintext m → g^m (additive homomorphism under ciphertext multiply).
	 *
	 * @param int        $m Small non-negative exponent.
	 * @param BigInteger $y Public key.
	 * @return array{c1: BigInteger, c2: BigInteger}|WP_Error
	 */
	public function encrypt_exponential( $m, BigInteger $y ) {
		$m = max( 0, (int) $m );
		$gm = $this->g->modPow( new BigInteger( (string) $m ), $this->p );
		$k  = self::random_range( $this->q );
		$c1 = $this->g->modPow( $k, $this->p );
		$c2 = $gm->multiply( $y->modPow( $k, $this->p ) )->mod( $this->p );
		return array(
			'c1' => $c1,
			'c2' => $c2,
		);
	}

	/**
	 * Decrypt exponential ciphertext to g^m.
	 *
	 * @param BigInteger $c1 Component 1.
	 * @param BigInteger $c2 Component 2.
	 * @param BigInteger $x  Private key.
	 * @return BigInteger g^m
	 */
	public function decrypt_exponential( BigInteger $c1, BigInteger $c2, BigInteger $x ) {
		return $this->decrypt( $c1, $c2, $x );
	}

	/**
	 * Multiply ciphertexts (homomorphic combine).
	 *
	 * @param array<int, array{c1: BigInteger, c2: BigInteger}> $parts Components.
	 * @return array{c1: BigInteger, c2: BigInteger}
	 */
	public function multiply_ciphertexts( array $parts ) {
		$one = new BigInteger( '1' );
		$c1  = $one;
		$c2  = $one;
		foreach ( $parts as $part ) {
			$c1 = $c1->multiply( $part['c1'] )->mod( $this->p );
			$c2 = $c2->multiply( $part['c2'] )->mod( $this->p );
		}
		return array( 'c1' => $c1, 'c2' => $c2 );
	}

	/**
	 * Discrete log of h = g^x for small x (brute force).
	 *
	 * @param BigInteger $h   Target.
	 * @param int        $max Maximum exponent.
	 * @return int|null
	 */
	public function discrete_log_small( BigInteger $h, $max = 500000 ) {
		$one = new BigInteger( '1' );
		$cur = $one;
		for ( $i = 0; $i <= $max; $i++ ) {
			if ( $cur->equals( $h ) ) {
				return $i;
			}
			$cur = $cur->multiply( $this->g )->mod( $this->p );
		}
		return null;
	}

	/**
	 * Map small integer vote payload into Z_p* (for tests / future tally).
	 *
	 * @param int $value Vote encoding.
	 * @return BigInteger|WP_Error
	 */
	public function encode_vote_integer( $value ) {
		$value = absint( $value );
		if ( $value < 1 ) {
			return new WP_Error( 'evote_invalid_vote', __( 'Vote encoding must be a positive integer.', 'decentralized-evoting' ) );
		}
		$m = new BigInteger( (string) $value );
		if ( $m->compare( $this->p ) >= 0 ) {
			return new WP_Error( 'evote_vote_too_large', __( 'Vote encoding exceeds field size.', 'decentralized-evoting' ) );
		}
		return $m;
	}

	/**
	 * Ensure g generates the order-q subgroup.
	 *
	 * @param BigInteger $g Candidate generator.
	 * @return BigInteger
	 */
	private function normalize_generator( BigInteger $g ) {
		$two = new BigInteger( '2' );
		$h   = $g->compare( $two ) < 0 ? $two : $g;
		$gen = $h->modPow( $two, $this->p );
		if ( $gen->equals( new BigInteger( '1' ) ) ) {
			$gen = $two->modPow( $two, $this->p );
		}
		return $gen;
	}

	/**
	 * Uniform random in [1, max).
	 *
	 * @param BigInteger $max Upper bound (exclusive of 0 result).
	 * @return BigInteger
	 */
	public static function random_range( BigInteger $max ) {
		$one  = new BigInteger( '1' );
		$bits = (int) $max->getLength();
		$bytes = (int) ceil( $bits / 8 ) + 8;

		$zero = new BigInteger( '0' );
		do {
			$hex = bin2hex( random_bytes( max( 16, $bytes ) ) );
			$n   = new BigInteger( $hex, 16 );
			$n   = $n->mod( $max );
		} while ( $n->compare( $one ) < 0 || $n->equals( $zero ) );

		return $n;
	}

	/**
	 * @param BigInteger $n Value.
	 * @return string Lowercase hex without prefix.
	 */
	public static function to_hex( BigInteger $n ) {
		$hex = strtolower( $n->toHex() );
		if ( '0' === $hex ) {
			return '0';
		}
		return ltrim( $hex, '0' ) ?: '0';
	}

	/**
	 * @param string $hex Hex string.
	 * @return BigInteger
	 */
	public static function from_hex( $hex ) {
		$hex = preg_replace( '/^0x/i', '', trim( (string) $hex ) );
		return new BigInteger( $hex, 16 );
	}
}
