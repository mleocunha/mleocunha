<?php
/**
 * Shamir secret sharing over a prime field (modular ElGamal private key).
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use phpseclib3\Math\BigInteger;

/**
 * Split and combine secrets using Shamir's scheme in GF(prime).
 */
class EVote_Shamir {

	/**
	 * Split a secret into n shares; any t shares reconstruct the secret.
	 *
	 * @param BigInteger $secret    Secret value in [0, prime).
	 * @param int        $threshold Minimum shares required (t).
	 * @param int        $shares    Total shares (n).
	 * @param BigInteger $prime     Field prime (subgroup order q).
	 * @return array<int, array{share_index: int, x: string, value: string}>|WP_Error
	 */
	public static function split( BigInteger $secret, $threshold, $shares, BigInteger $prime ) {
		$valid = EVote_Crypto::validate_sss_params( $threshold, $shares );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$secret = $secret->mod( $prime );
		$coeffs = array( $secret );

		for ( $i = 1; $i < $threshold; $i++ ) {
			$coeffs[] = EVote_Elgamal::random_range( $prime );
		}

		$out = array();
		for ( $index = 1; $index <= $shares; $index++ ) {
			$x = new BigInteger( (string) $index );
			$y = self::evaluate_polynomial( $coeffs, $x, $prime );
			$out[] = array(
				'share_index' => $index,
				'x'           => EVote_Elgamal::to_hex( $x ),
				'value'       => EVote_Elgamal::to_hex( $y ),
			);
		}

		return $out;
	}

	/**
	 * Reconstruct secret from at least threshold shares.
	 *
	 * @param array<int, array{x: string, value: string, share_index?: int}> $share_points Share points.
	 * @param BigInteger                                                     $prime        Field prime.
	 * @return BigInteger|WP_Error
	 */
	public static function combine( array $share_points, BigInteger $prime ) {
		if ( count( $share_points ) < 2 ) {
			return new WP_Error( 'evote_sss_insufficient', __( 'At least two shares are required.', 'decentralized-evoting' ) );
		}

		$points = array();
		foreach ( $share_points as $share ) {
			if ( empty( $share['x'] ) || empty( $share['value'] ) ) {
				return new WP_Error( 'evote_sss_invalid_share', __( 'Each share must include x and value.', 'decentralized-evoting' ) );
			}
			$x = EVote_Elgamal::from_hex( $share['x'] )->mod( $prime );
			$y = EVote_Elgamal::from_hex( $share['value'] )->mod( $prime );
			$key = (string) $x;
			if ( isset( $points[ $key ] ) ) {
				return new WP_Error( 'evote_sss_duplicate_x', __( 'Duplicate share evaluation points.', 'decentralized-evoting' ) );
			}
			$points[ $key ] = array( 'x' => $x, 'y' => $y );
		}

		$secret    = new BigInteger( '0' );
		$zero      = new BigInteger( '0' );
		$point_list = array_values( $points );

		foreach ( $point_list as $i => $point ) {
			$numerator   = new BigInteger( '1' );
			$denominator = new BigInteger( '1' );

			foreach ( $point_list as $j => $other ) {
				if ( $i === $j ) {
					continue;
				}
				$numerator   = $numerator->multiply( $zero->subtract( $other['x'] ) )->mod( $prime );
				$denominator = $denominator->multiply( $point['x']->subtract( $other['x'] ) )->mod( $prime );
			}

			$inv = $denominator->modInverse( $prime );
			if ( false === $inv ) {
				return new WP_Error( 'evote_sss_lagrange', __( 'Failed to compute Lagrange coefficient.', 'decentralized-evoting' ) );
			}

			$lagrange = $numerator->multiply( $inv )->mod( $prime );
			$term     = $point['y']->multiply( $lagrange )->mod( $prime );
			$secret   = $secret->add( $term )->mod( $prime );
		}

		return $secret;
	}

	/**
	 * @param BigInteger[] $coeffs Polynomial coefficients (a0 = secret).
	 * @param BigInteger   $x      Evaluation point.
	 * @param BigInteger   $prime  Modulus.
	 * @return BigInteger
	 */
	private static function evaluate_polynomial( array $coeffs, BigInteger $x, BigInteger $prime ) {
		$result = new BigInteger( '0' );
		$power  = new BigInteger( '1' );

		foreach ( $coeffs as $coeff ) {
			$term   = $coeff->multiply( $power )->mod( $prime );
			$result = $result->add( $term )->mod( $prime );
			$power  = $power->multiply( $x )->mod( $prime );
		}

		return $result;
	}
}
