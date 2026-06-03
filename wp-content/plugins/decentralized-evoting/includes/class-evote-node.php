<?php
/**
 * Node type detection and capability gating.
 *
 * Define in wp-config.php, e.g. define( 'EVOTE_NODE_TYPE', 'polling' );
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site role within the three-node e-voting architecture.
 */
class EVote_Node {

	const TYPE_GENERATOR = 'generator';
	const TYPE_POLLING   = 'polling';
	const TYPE_TALLY     = 'tally';

	/**
	 * Valid node type slugs.
	 *
	 * @var string[]
	 */
	private static $valid_types = array(
		self::TYPE_GENERATOR,
		self::TYPE_POLLING,
		self::TYPE_TALLY,
	);

	/**
	 * Resolved node type for this installation.
	 *
	 * @var string|null
	 */
	private static $type = null;

	/**
	 * Current node type (defaults to polling for local dev when undefined).
	 *
	 * @return string
	 */
	public static function get_type() {
		if ( null !== self::$type ) {
			return self::$type;
		}

		if ( defined( 'EVOTE_NODE_TYPE' ) && in_array( EVOTE_NODE_TYPE, self::$valid_types, true ) ) {
			self::$type = EVOTE_NODE_TYPE;
		} else {
			self::$type = self::TYPE_POLLING;
		}

		return self::$type;
	}

	/**
	 * Human-readable label for admin UI.
	 *
	 * @return string
	 */
	public static function get_label() {
		$labels = array(
			self::TYPE_GENERATOR => __( 'Key Generation (Authority)', 'decentralized-evoting' ),
			self::TYPE_POLLING   => __( 'Polling Station', 'decentralized-evoting' ),
			self::TYPE_TALLY     => __( 'Tally Board', 'decentralized-evoting' ),
		);

		return $labels[ self::get_type() ] ?? self::get_type();
	}

	/**
	 * @param string $type Node slug.
	 * @return bool
	 */
	public static function is( $type ) {
		return self::get_type() === $type;
	}

	/**
	 * Whether EVOTE_NODE_TYPE was explicitly configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return defined( 'EVOTE_NODE_TYPE' ) && in_array( EVOTE_NODE_TYPE, self::$valid_types, true );
	}

	/**
	 * @return string[]
	 */
	public static function get_valid_types() {
		return self::$valid_types;
	}
}
