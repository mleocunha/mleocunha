<?php
/**
 * Database schema definitions.
 *
 * @package RelataSoft\SecureElectionSuite\Database
 */

namespace RelataSoft\SecureElectionSuite\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Schema version and table definitions.
 */
class Schema {

	public const RSES_DB_VERSION = '1.1.0';

	/**
	 * Get all table creation SQL statements.
	 *
	 * @return array<string,string> Table name suffix => CREATE TABLE SQL.
	 */
	public static function rses_get_tables(): array {
		global $wpdb;

		$rses_charset = $wpdb->get_charset_collate();

		return array(
			'rses_keys' => "CREATE TABLE {$wpdb->prefix}rses_keys (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				election_round_id BIGINT UNSIGNED NULL,
				key_label VARCHAR(255) NOT NULL,
				public_p LONGTEXT NOT NULL,
				public_q LONGTEXT NOT NULL,
				public_g LONGTEXT NOT NULL,
				public_y LONGTEXT NOT NULL,
				key_size INT NOT NULL,
				encoding_mode VARCHAR(50) NOT NULL DEFAULT 'g_power_count',
				private_key_persisted TINYINT(1) NOT NULL DEFAULT 0,
				private_x_encrypted LONGTEXT NULL,
				field_prime LONGTEXT NULL,
				threshold_t INT NULL,
				total_n INT NULL,
				scheme_id VARCHAR(64) NULL,
				ceremony_id VARCHAR(64) NULL,
				commitments_json LONGTEXT NULL,
				ceremony_transcript_json LONGTEXT NULL,
				public_transcript_hash CHAR(64) NULL,
				ceremony_status VARCHAR(50) NOT NULL DEFAULT 'active',
				description TEXT NULL,
				attachments LONGTEXT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NULL,
				deleted_at DATETIME NULL,
				is_deleted TINYINT(1) NOT NULL DEFAULT 0,
				audit_hash CHAR(64) NULL,
				PRIMARY KEY (id),
				KEY election_round_id (election_round_id),
				KEY is_deleted (is_deleted)
			) {$rses_charset};",

			'rses_shares' => "CREATE TABLE {$wpdb->prefix}rses_shares (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				key_id BIGINT UNSIGNED NOT NULL,
				election_round_id BIGINT UNSIGNED NULL,
				official_user_id BIGINT UNSIGNED NOT NULL,
				share_index INT NOT NULL,
				share_payload_encrypted LONGTEXT NOT NULL,
				threshold_t INT NOT NULL,
				total_n INT NOT NULL,
				field_prime LONGTEXT NOT NULL,
				status VARCHAR(50) NOT NULL DEFAULT 'assigned',
				exported_at DATETIME NULL,
				submitted_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				audit_hash CHAR(64) NULL,
				PRIMARY KEY (id),
				KEY key_id (key_id),
				KEY official_user_id (official_user_id),
				UNIQUE KEY key_share_index (key_id, share_index)
			) {$rses_charset};",

			'rses_elections' => "CREATE TABLE {$wpdb->prefix}rses_elections (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				title VARCHAR(255) NOT NULL,
				description LONGTEXT NULL,
				status VARCHAR(50) NOT NULL DEFAULT 'draft',
				voting_method VARCHAR(100) NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL,
				opens_at DATETIME NULL,
				closes_at DATETIME NULL,
				current_round_id BIGINT UNSIGNED NULL,
				settings_json LONGTEXT NULL,
				audit_hash CHAR(64) NULL,
				PRIMARY KEY (id),
				KEY status (status)
			) {$rses_charset};",

			'rses_election_rounds' => "CREATE TABLE {$wpdb->prefix}rses_election_rounds (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				election_id BIGINT UNSIGNED NOT NULL,
				round_number INT NOT NULL,
				round_type VARCHAR(50) NOT NULL DEFAULT 'initial',
				title VARCHAR(255) NOT NULL,
				status VARCHAR(50) NOT NULL DEFAULT 'draft',
				key_id BIGINT UNSIGNED NULL,
				threshold_t INT NULL,
				total_n INT NULL,
				created_at DATETIME NOT NULL,
				opened_at DATETIME NULL,
				closed_at DATETIME NULL,
				audit_hash CHAR(64) NULL,
				PRIMARY KEY (id),
				KEY election_id (election_id),
				KEY key_id (key_id)
			) {$rses_charset};",

			'rses_ballot_questions' => "CREATE TABLE {$wpdb->prefix}rses_ballot_questions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				election_id BIGINT UNSIGNED NOT NULL,
				round_id BIGINT UNSIGNED NOT NULL,
				question_title VARCHAR(255) NOT NULL,
				question_description LONGTEXT NULL,
				question_type VARCHAR(100) NOT NULL,
				min_choices INT NOT NULL DEFAULT 0,
				max_choices INT NOT NULL DEFAULT 1,
				order_index INT NOT NULL DEFAULT 0,
				settings_json LONGTEXT NULL,
				audit_hash CHAR(64) NULL,
				PRIMARY KEY (id),
				KEY election_id (election_id),
				KEY round_id (round_id)
			) {$rses_charset};",

			'rses_ballot_options' => "CREATE TABLE {$wpdb->prefix}rses_ballot_options (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				question_id BIGINT UNSIGNED NOT NULL,
				candidate_user_id BIGINT UNSIGNED NULL,
				option_label VARCHAR(255) NOT NULL,
				option_value VARCHAR(255) NULL,
				order_index INT NOT NULL DEFAULT 0,
				metadata_json LONGTEXT NULL,
				audit_hash CHAR(64) NULL,
				PRIMARY KEY (id),
				KEY question_id (question_id)
			) {$rses_charset};",

			'rses_encrypted_votes' => "CREATE TABLE {$wpdb->prefix}rses_encrypted_votes (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				election_id BIGINT UNSIGNED NOT NULL,
				round_id BIGINT UNSIGNED NOT NULL,
				question_id BIGINT UNSIGNED NOT NULL,
				option_id BIGINT UNSIGNED NULL,
				voter_user_id BIGINT UNSIGNED NOT NULL,
				ciphertext_alpha LONGTEXT NOT NULL,
				ciphertext_beta LONGTEXT NOT NULL,
				encrypted_payload_json LONGTEXT NULL,
				vote_hash CHAR(64) NOT NULL,
				cast_at DATETIME NOT NULL,
				ip_hash CHAR(64) NULL,
				user_agent_hash CHAR(64) NULL,
				audit_hash CHAR(64) NULL,
				PRIMARY KEY (id),
				KEY election_id (election_id),
				KEY round_id (round_id),
				KEY voter_user_id (voter_user_id),
				UNIQUE KEY voter_round_question_option (voter_user_id, round_id, question_id, option_id)
			) {$rses_charset};",

			'rses_encrypted_tallies' => "CREATE TABLE {$wpdb->prefix}rses_encrypted_tallies (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				election_id BIGINT UNSIGNED NOT NULL,
				round_id BIGINT UNSIGNED NOT NULL,
				question_id BIGINT UNSIGNED NOT NULL,
				option_id BIGINT UNSIGNED NULL,
				aggregate_alpha LONGTEXT NOT NULL,
				aggregate_beta LONGTEXT NOT NULL,
				ballot_count INT NOT NULL DEFAULT 0,
				max_decode_count INT NOT NULL DEFAULT 0,
				aggregation_proof_json LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				audit_hash CHAR(64) NULL,
				PRIMARY KEY (id),
				KEY election_id (election_id),
				KEY round_id (round_id),
				KEY question_id (question_id)
			) {$rses_charset};",

			'rses_tally_imports' => "CREATE TABLE {$wpdb->prefix}rses_tally_imports (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				source_site_url VARCHAR(255) NULL,
				election_external_id VARCHAR(255) NULL,
				round_external_id VARCHAR(255) NULL,
				import_manifest_json LONGTEXT NOT NULL,
				import_hash CHAR(64) NOT NULL,
				imported_by BIGINT UNSIGNED NOT NULL,
				imported_at DATETIME NOT NULL,
				status VARCHAR(50) NOT NULL DEFAULT 'pending',
				audit_hash CHAR(64) NULL,
				PRIMARY KEY (id),
				KEY status (status)
			) {$rses_charset};",

			'rses_official_share_submissions' => "CREATE TABLE {$wpdb->prefix}rses_official_share_submissions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				tally_import_id BIGINT UNSIGNED NOT NULL,
				key_id BIGINT UNSIGNED NOT NULL,
				election_round_id BIGINT UNSIGNED NOT NULL,
				official_user_id BIGINT UNSIGNED NOT NULL,
				share_index INT NOT NULL,
				share_payload_encrypted LONGTEXT NOT NULL,
				submitted_at DATETIME NOT NULL,
				audit_hash CHAR(64) NULL,
				PRIMARY KEY (id),
				KEY tally_import_id (tally_import_id),
				KEY key_id (key_id),
				UNIQUE KEY tally_share_index (tally_import_id, share_index)
			) {$rses_charset};",

			'rses_certifications' => "CREATE TABLE {$wpdb->prefix}rses_certifications (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				election_id BIGINT UNSIGNED NULL,
				round_id BIGINT UNSIGNED NULL,
				tally_import_id BIGINT UNSIGNED NULL,
				certification_status VARCHAR(50) NOT NULL,
				encrypted_sum_hash CHAR(64) NOT NULL,
				decrypted_result_hash CHAR(64) NOT NULL,
				verification_report_json LONGTEXT NOT NULL,
				pdf_attachment_id BIGINT UNSIGNED NULL,
				zip_attachment_id BIGINT UNSIGNED NULL,
				certified_by BIGINT UNSIGNED NOT NULL,
				certified_at DATETIME NOT NULL,
				audit_hash CHAR(64) NULL,
				PRIMARY KEY (id),
				KEY tally_import_id (tally_import_id)
			) {$rses_charset};",

			'rses_audit_log' => "CREATE TABLE {$wpdb->prefix}rses_audit_log (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				actor_user_id BIGINT UNSIGNED NULL,
				action VARCHAR(255) NOT NULL,
				object_type VARCHAR(255) NOT NULL,
				object_id BIGINT UNSIGNED NULL,
				previous_hash CHAR(64) NULL,
				current_hash CHAR(64) NOT NULL,
				payload_json LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY action (action),
				KEY object_type (object_type)
			) {$rses_charset};",
		);
	}

	/**
	 * Get full table name.
	 *
	 * @param string $suffix Table suffix.
	 * @return string
	 */
	public static function rses_table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . $suffix;
	}
}
