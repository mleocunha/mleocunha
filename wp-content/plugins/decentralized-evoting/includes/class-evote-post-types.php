<?php
/**
 * Custom post types and taxonomies for the polling station node.
 *
 * @package DecentralizedEvoting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers election content model (runnings, candidates, parties, slates).
 */
class EVote_Post_Types {

	/**
	 * Hook registration.
	 */
	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
	}

	/**
	 * Register all CPTs and taxonomies.
	 */
	public static function register() {
		self::register_running();
		self::register_candidate();
		self::register_modality();
		self::register_party_taxonomy();
		self::register_slate_taxonomy();
	}

	/**
	 * Election event (running).
	 */
	public static function register_running() {
		$labels = array(
			'name'               => __( 'Runnings', 'decentralized-evoting' ),
			'singular_name'      => __( 'Running', 'decentralized-evoting' ),
			'menu_name'          => __( 'E-Voting', 'decentralized-evoting' ),
			'add_new'            => __( 'Add Running', 'decentralized-evoting' ),
			'add_new_item'       => __( 'Add New Running', 'decentralized-evoting' ),
			'edit_item'          => __( 'Edit Running', 'decentralized-evoting' ),
			'new_item'           => __( 'New Running', 'decentralized-evoting' ),
			'view_item'          => __( 'View Running', 'decentralized-evoting' ),
			'search_items'       => __( 'Search Runnings', 'decentralized-evoting' ),
			'not_found'          => __( 'No runnings found.', 'decentralized-evoting' ),
			'not_found_in_trash' => __( 'No runnings found in Trash.', 'decentralized-evoting' ),
		);

		register_post_type(
			'evote_running',
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'evote-dashboard',
				'menu_icon'           => 'dashicons-privacy',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor' ),
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
			)
		);
	}

	/**
	 * Candidates standing in elections.
	 */
	public static function register_candidate() {
		$labels = array(
			'name'          => __( 'Candidates', 'decentralized-evoting' ),
			'singular_name' => __( 'Candidate', 'decentralized-evoting' ),
			'add_new_item'  => __( 'Add New Candidate', 'decentralized-evoting' ),
			'edit_item'     => __( 'Edit Candidate', 'decentralized-evoting' ),
		);

		register_post_type(
			'evote_candidate',
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'evote-dashboard',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'thumbnail', 'excerpt' ),
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
			)
		);
	}

	/**
	 * Reusable voting modality templates (single, multiple, ranked, etc.).
	 */
	public static function register_modality() {
		$labels = array(
			'name'          => __( 'Modalities', 'decentralized-evoting' ),
			'singular_name' => __( 'Modality', 'decentralized-evoting' ),
			'add_new_item'  => __( 'Add New Modality', 'decentralized-evoting' ),
		);

		register_post_type(
			'evote_modality',
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'evote-dashboard',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor' ),
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
			)
		);
	}

	/**
	 * Party association for candidates.
	 */
	public static function register_party_taxonomy() {
		$labels = array(
			'name'          => __( 'Parties', 'decentralized-evoting' ),
			'singular_name' => __( 'Party', 'decentralized-evoting' ),
			'search_items'  => __( 'Search Parties', 'decentralized-evoting' ),
			'all_items'     => __( 'All Parties', 'decentralized-evoting' ),
			'edit_item'     => __( 'Edit Party', 'decentralized-evoting' ),
			'update_item'   => __( 'Update Party', 'decentralized-evoting' ),
			'add_new_item'  => __( 'Add New Party', 'decentralized-evoting' ),
		);

		register_taxonomy(
			'evote_party',
			array( 'evote_candidate' ),
			array(
				'labels'            => $labels,
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Slate association for candidates.
	 */
	public static function register_slate_taxonomy() {
		$labels = array(
			'name'          => __( 'Slates', 'decentralized-evoting' ),
			'singular_name' => __( 'Slate', 'decentralized-evoting' ),
			'search_items'  => __( 'Search Slates', 'decentralized-evoting' ),
			'all_items'     => __( 'All Slates', 'decentralized-evoting' ),
			'edit_item'     => __( 'Edit Slate', 'decentralized-evoting' ),
			'update_item'   => __( 'Update Slate', 'decentralized-evoting' ),
			'add_new_item'  => __( 'Add New Slate', 'decentralized-evoting' ),
		);

		register_taxonomy(
			'evote_slate',
			array( 'evote_candidate' ),
			array(
				'labels'            => $labels,
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Meta keys stored on evote_running posts.
	 *
	 * @return string[]
	 */
	public static function running_meta_keys() {
		return array(
			'_evote_start_datetime',
			'_evote_end_datetime',
			'_evote_public_key_json',
			'_evote_modality_type',
			'_evote_modality_id',
			'_evote_max_choices',
			'_evote_candidate_ids',
			'_evote_status',
		);
	}

	/**
	 * Meta keys stored on evote_modality posts.
	 *
	 * @return string[]
	 */
	public static function modality_meta_keys() {
		return array(
			'_evote_modality_type',
			'_evote_max_choices',
			'_evote_allow_abstain',
			'_evote_ranked_levels',
		);
	}
}
