<?php
/**
 * Open elections snapshot for admin scrapers and elector welcome.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Frontend\JourneySettings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a structured list of open election rounds.
 */
class OpenElectionsService {

	/**
	 * Snapshot of every open round (and nested ballot metadata).
	 *
	 * @return array<string,mixed>
	 */
	public static function rses_snapshot(): array {
		$rses_elections_out = array();
		$rses_all           = ElectionRepository::rses_list();

		foreach ( $rses_all as $rses_election ) {
			$rses_election_id = (int) $rses_election->id;
			$rses_rounds_out  = array();

			foreach ( ElectionRepository::rses_get_rounds( $rses_election_id ) as $rses_round ) {
				if ( 'open' !== (string) $rses_round->status ) {
					continue;
				}

				$rses_round_id   = (int) $rses_round->id;
				$rses_questions  = array();

				foreach ( ElectionRepository::rses_get_questions( $rses_round_id ) as $rses_question ) {
					$rses_qid     = (int) $rses_question->id;
					$rses_options = array();

					foreach ( ElectionRepository::rses_get_options( $rses_qid ) as $rses_option ) {
						$rses_options[] = array(
							'id'    => (int) $rses_option->id,
							'label' => (string) $rses_option->option_label,
						);
					}

					$rses_questions[] = array(
						'id'          => $rses_qid,
						'title'       => (string) $rses_question->question_title,
						'type'        => (string) $rses_question->question_type,
						'min_choices' => (int) $rses_question->min_choices,
						'max_choices' => (int) $rses_question->max_choices,
						'options'     => $rses_options,
					);
				}

				$rses_rounds_out[] = array(
					'id'         => $rses_round_id,
					'title'      => (string) $rses_round->title,
					'status'     => (string) $rses_round->status,
					'opened_at'  => $rses_round->opened_at ?? null,
					'questions'  => $rses_questions,
				);
			}

			if ( empty( $rses_rounds_out ) ) {
				continue;
			}

			$rses_elections_out[] = array(
				'id'     => $rses_election_id,
				'title'  => (string) $rses_election->title,
				'status' => (string) $rses_election->status,
				'rounds' => $rses_rounds_out,
			);
		}

		return array(
			'generated_at' => gmdate( 'c' ),
			'journey'      => array(
				'welcome'   => JourneySettings::rses_page_url( 'welcome_page_id' ),
				'booth'     => JourneySettings::rses_page_url( 'booth_page_id' ),
				'thank_you' => JourneySettings::rses_page_url( 'thank_you_page_id' ),
			),
			'elections'    => $rses_elections_out,
		);
	}

	/**
	 * Flat list of open (election_id, round_id) pairs for welcome links.
	 *
	 * @return array<int,array{election_id:int,round_id:int,title:string,round_title:string}>
	 */
	public static function rses_open_round_links(): array {
		$rses_links = array();
		$rses_snap  = self::rses_snapshot();

		foreach ( $rses_snap['elections'] as $rses_election ) {
			foreach ( $rses_election['rounds'] as $rses_round ) {
				$rses_links[] = array(
					'election_id' => (int) $rses_election['id'],
					'round_id'    => (int) $rses_round['id'],
					'title'       => (string) $rses_election['title'],
					'round_title' => (string) $rses_round['title'],
				);
			}
		}

		return $rses_links;
	}
}
