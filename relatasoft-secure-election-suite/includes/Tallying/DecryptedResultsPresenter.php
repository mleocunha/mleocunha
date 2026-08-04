<?php
/**
 * Human-readable decrypted tally presentation.
 *
 * @package RelataSoft\SecureElectionSuite\Tallying
 */

namespace RelataSoft\SecureElectionSuite\Tallying;

defined( 'ABSPATH' ) || exit;

/**
 * Joins raw decrypted {question_id,option_id,count} rows with ballot labels.
 */
class DecryptedResultsPresenter {

	public const RSES_SORT_COUNT_DESC  = 'count_desc';
	public const RSES_SORT_COUNT_ASC   = 'count_asc';
	public const RSES_SORT_LABEL       = 'label';
	public const RSES_SORT_BALLOT      = 'ballot';

	/**
	 * Allowed sort modes.
	 *
	 * @return array<string,string> mode => label
	 */
	public static function rses_sort_modes(): array {
		return array(
			self::RSES_SORT_COUNT_DESC => __( 'Most voted first', 'relatasoft-secure-election-suite' ),
			self::RSES_SORT_COUNT_ASC  => __( 'Least voted first', 'relatasoft-secure-election-suite' ),
			self::RSES_SORT_LABEL      => __( 'Option label (A–Z)', 'relatasoft-secure-election-suite' ),
			self::RSES_SORT_BALLOT     => __( 'Ballot order', 'relatasoft-secure-election-suite' ),
		);
	}

	/**
	 * Normalize a sort mode.
	 *
	 * @param string $sort Requested sort.
	 */
	public static function rses_normalize_sort( string $sort ): string {
		$rses_modes = self::rses_sort_modes();
		return isset( $rses_modes[ $sort ] ) ? $sort : self::RSES_SORT_COUNT_DESC;
	}

	/**
	 * Build question/option label indexes from an imported ballot.
	 *
	 * @param array<int,mixed> $ballot Ballot from voting export.
	 * @return array{
	 *   questions: array<int,array{title:string,order:int,description:string}>,
	 *   options: array<int,array{label:string,question_id:int,order:int}>,
	 *   question_order: array<int,int>
	 * }
	 */
	public static function rses_index_ballot( array $ballot ): array {
		$rses_questions = array();
		$rses_options   = array();
		$rses_q_order   = array();
		$rses_qi        = 0;

		foreach ( $ballot as $rses_block ) {
			if ( ! is_array( $rses_block ) ) {
				continue;
			}
			$rses_q = is_array( $rses_block['question'] ?? null ) ? $rses_block['question'] : array();
			$rses_qid = (int) ( $rses_q['id'] ?? $rses_q['question_id'] ?? 0 );
			if ( $rses_qid < 1 ) {
				continue;
			}

			$rses_questions[ $rses_qid ] = array(
				'title'       => (string) ( $rses_q['question_title'] ?? $rses_q['title'] ?? '' ),
				'description' => (string) ( $rses_q['question_description'] ?? $rses_q['description'] ?? '' ),
				'order'       => isset( $rses_q['order_index'] ) ? (int) $rses_q['order_index'] : $rses_qi,
			);
			$rses_q_order[] = $rses_qid;
			++$rses_qi;

			$rses_opts = is_array( $rses_block['options'] ?? null ) ? $rses_block['options'] : array();
			$rses_oi   = 0;
			foreach ( $rses_opts as $rses_opt ) {
				if ( ! is_array( $rses_opt ) ) {
					continue;
				}
				$rses_oid = (int) ( $rses_opt['id'] ?? $rses_opt['option_id'] ?? 0 );
				if ( $rses_oid < 1 ) {
					continue;
				}
				$rses_options[ $rses_oid ] = array(
					'label'       => (string) ( $rses_opt['option_label'] ?? $rses_opt['label'] ?? '' ),
					'question_id' => $rses_qid,
					'order'       => isset( $rses_opt['order_index'] ) ? (int) $rses_opt['order_index'] : $rses_oi,
				);
				++$rses_oi;
			}
		}

		return array(
			'questions'      => $rses_questions,
			'options'        => $rses_options,
			'question_order' => $rses_q_order,
		);
	}

	/**
	 * Humanize decrypted result rows.
	 *
	 * @param array<int,array<string,mixed>> $decrypted Raw rows.
	 * @param array<int,mixed>               $ballot    Ballot from import.
	 * @param string                         $sort      Sort mode.
	 * @return array<string,mixed>
	 */
	public static function rses_humanize( array $decrypted, array $ballot, string $sort = self::RSES_SORT_COUNT_DESC ): array {
		$rses_sort  = self::rses_normalize_sort( $sort );
		$rses_index = self::rses_index_ballot( $ballot );

		$rses_by_q = array();
		foreach ( $decrypted as $rses_row ) {
			if ( ! is_array( $rses_row ) ) {
				continue;
			}
			$rses_qid = (int) ( $rses_row['question_id'] ?? 0 );
			$rses_oid = isset( $rses_row['option_id'] ) && null !== $rses_row['option_id']
				? (int) $rses_row['option_id']
				: 0;
			$rses_count = (int) ( $rses_row['count'] ?? 0 );

			if ( ! isset( $rses_by_q[ $rses_qid ] ) ) {
				$rses_qmeta = $rses_index['questions'][ $rses_qid ] ?? null;
				$rses_by_q[ $rses_qid ] = array(
					'question_id'    => $rses_qid,
					'question_title' => $rses_qmeta && '' !== $rses_qmeta['title']
						? $rses_qmeta['title']
						: sprintf(
							/* translators: %d: question id */
							__( 'Question #%d', 'relatasoft-secure-election-suite' ),
							$rses_qid
						),
					'question_description' => $rses_qmeta['description'] ?? '',
					'ballot_order'         => $rses_qmeta['order'] ?? $rses_qid,
					'total_votes'          => 0,
					'options'              => array(),
				);
			}

			$rses_ometa = $rses_oid > 0 ? ( $rses_index['options'][ $rses_oid ] ?? null ) : null;
			$rses_by_q[ $rses_qid ]['options'][] = array(
				'option_id'    => $rses_oid > 0 ? $rses_oid : null,
				'option_label' => $rses_ometa && '' !== $rses_ometa['label']
					? $rses_ometa['label']
					: (
						$rses_oid > 0
							? sprintf(
								/* translators: %d: option id */
								__( 'Option #%d', 'relatasoft-secure-election-suite' ),
								$rses_oid
							)
							: __( '(no option)', 'relatasoft-secure-election-suite' )
					),
				'count'        => $rses_count,
				'ballot_order' => $rses_ometa['order'] ?? $rses_oid,
			);
			$rses_by_q[ $rses_qid ]['total_votes'] += $rses_count;
		}

		// Include ballot options with zero votes that did not appear in decryption.
		foreach ( $rses_index['options'] as $rses_oid => $rses_ometa ) {
			$rses_qid = (int) $rses_ometa['question_id'];
			if ( ! isset( $rses_by_q[ $rses_qid ] ) ) {
				continue;
			}
			$rses_found = false;
			foreach ( $rses_by_q[ $rses_qid ]['options'] as $rses_existing ) {
				if ( (int) ( $rses_existing['option_id'] ?? 0 ) === (int) $rses_oid ) {
					$rses_found = true;
					break;
				}
			}
			if ( ! $rses_found ) {
				$rses_by_q[ $rses_qid ]['options'][] = array(
					'option_id'    => (int) $rses_oid,
					'option_label' => '' !== $rses_ometa['label']
						? $rses_ometa['label']
						: sprintf(
							/* translators: %d: option id */
							__( 'Option #%d', 'relatasoft-secure-election-suite' ),
							(int) $rses_oid
						),
					'count'        => 0,
					'ballot_order' => (int) $rses_ometa['order'],
				);
			}
		}

		foreach ( $rses_by_q as &$rses_qblock ) {
			$total = max( 0, (int) $rses_qblock['total_votes'] );
			foreach ( $rses_qblock['options'] as &$rses_opt ) {
				$rses_opt['share_pct'] = $total > 0
					? round( ( (int) $rses_opt['count'] / $total ) * 100, 2 )
					: 0.0;
			}
			unset( $rses_opt );
			self::rses_sort_options( $rses_qblock['options'], $rses_sort );
			$rses_rank = 1;
			foreach ( $rses_qblock['options'] as &$rses_opt ) {
				$rses_opt['rank'] = $rses_rank;
				++$rses_rank;
			}
			unset( $rses_opt );
		}
		unset( $rses_qblock );

		$rses_questions = array_values( $rses_by_q );
		usort(
			$rses_questions,
			static function ( array $a, array $b ): int {
				$oa = (int) ( $a['ballot_order'] ?? 0 );
				$ob = (int) ( $b['ballot_order'] ?? 0 );
				if ( $oa === $ob ) {
					return (int) $a['question_id'] <=> (int) $b['question_id'];
				}
				return $oa <=> $ob;
			}
		);

		$rses_rows = array();
		foreach ( $rses_questions as $rses_qblock ) {
			foreach ( $rses_qblock['options'] as $rses_opt ) {
				$rses_rows[] = array(
					'question_id'    => (int) $rses_qblock['question_id'],
					'question_title' => (string) $rses_qblock['question_title'],
					'option_id'      => $rses_opt['option_id'],
					'option_label'   => (string) $rses_opt['option_label'],
					'count'          => (int) $rses_opt['count'],
					'share_pct'      => (float) $rses_opt['share_pct'],
					'rank'           => (int) $rses_opt['rank'],
				);
			}
		}

		return array(
			'sort'      => $rses_sort,
			'questions' => $rses_questions,
			'rows'      => $rses_rows,
		);
	}

	/**
	 * Sort option rows in place.
	 *
	 * @param array<int,array<string,mixed>> $options Options.
	 * @param string                         $sort    Sort mode.
	 */
	private static function rses_sort_options( array &$options, string $sort ): void {
		usort(
			$options,
			static function ( array $a, array $b ) use ( $sort ): int {
				switch ( $sort ) {
					case self::RSES_SORT_COUNT_ASC:
						$cmp = (int) $a['count'] <=> (int) $b['count'];
						break;
					case self::RSES_SORT_LABEL:
						$cmp = strcasecmp( (string) $a['option_label'], (string) $b['option_label'] );
						break;
					case self::RSES_SORT_BALLOT:
						$cmp = (int) ( $a['ballot_order'] ?? 0 ) <=> (int) ( $b['ballot_order'] ?? 0 );
						break;
					case self::RSES_SORT_COUNT_DESC:
					default:
						$cmp = (int) $b['count'] <=> (int) $a['count'];
						break;
				}
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				return (int) ( $a['option_id'] ?? 0 ) <=> (int) ( $b['option_id'] ?? 0 );
			}
		);
	}

	/**
	 * Build PDF text lines from a humanized result set.
	 *
	 * @param array<string,mixed> $report       Certification / display report meta.
	 * @param array<string,mixed> $humanized    Humanized structure from rses_humanize().
	 * @param bool                $include_raw  Append raw technical id/count lines (legacy).
	 * @return array<int,string>
	 */
	public static function rses_pdf_lines( array $report, array $humanized, bool $include_raw = true ): array {
		$rses_modes = self::rses_sort_modes();
		$rses_sort  = (string) ( $humanized['sort'] ?? self::RSES_SORT_COUNT_DESC );

		$rses_lines = array(
			__( 'RelataSoft Secure Election Suite - Certification Report', 'relatasoft-secure-election-suite' ),
			'',
			__( 'Election:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['election_title'] ?? '' ),
			__( 'Round:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['round_title'] ?? $report['round_number'] ?? '' ),
			__( 'Status:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['verification_status'] ?? '' ),
			__( 'Certified at:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['certified_at'] ?? '' ),
			__( 'Public key hash:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['public_key_hash'] ?? '' ),
			__( 'Decrypted result hash:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['decrypted_result_hash'] ?? '' ),
			__( 'Ballot count:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['ballot_count'] ?? 0 ),
			__( 'Threshold:', 'relatasoft-secure-election-suite' ) . ' ' . ( $report['threshold'] ?? 0 ),
			__( 'Results order:', 'relatasoft-secure-election-suite' ) . ' ' . ( $rses_modes[ $rses_sort ] ?? $rses_sort ),
			'',
			__( 'Results', 'relatasoft-secure-election-suite' ),
			str_repeat( '-', 40 ),
		);

		foreach ( $humanized['questions'] ?? array() as $rses_q ) {
			$rses_lines[] = '';
			$rses_lines[] = sprintf(
				'%s (#%d)',
				(string) ( $rses_q['question_title'] ?? '' ),
				(int) ( $rses_q['question_id'] ?? 0 )
			);
			if ( ! empty( $rses_q['question_description'] ) ) {
				$rses_lines[] = (string) $rses_q['question_description'];
			}
			$rses_lines[] = sprintf(
				/* translators: %d: total votes for the question */
				__( 'Total votes: %d', 'relatasoft-secure-election-suite' ),
				(int) ( $rses_q['total_votes'] ?? 0 )
			);

			foreach ( $rses_q['options'] ?? array() as $rses_opt ) {
				$rses_lines[] = sprintf(
					'  %d. %s — %d (%.2f%%)  [option_id=%s]',
					(int) ( $rses_opt['rank'] ?? 0 ),
					(string) ( $rses_opt['option_label'] ?? '' ),
					(int) ( $rses_opt['count'] ?? 0 ),
					(float) ( $rses_opt['share_pct'] ?? 0 ),
					null !== ( $rses_opt['option_id'] ?? null ) ? (string) (int) $rses_opt['option_id'] : '-'
				);
			}
		}

		if ( $include_raw ) {
			$rses_lines[] = '';
			$rses_lines[] = __( 'Raw decrypted results (technical)', 'relatasoft-secure-election-suite' );
			$rses_lines[] = str_repeat( '-', 40 );
			foreach ( $report['decrypted_results'] ?? array() as $rses_raw ) {
				if ( ! is_array( $rses_raw ) ) {
					continue;
				}
				$rses_lines[] = sprintf(
					'question_id=%s option_id=%s count=%d',
					(string) ( $rses_raw['question_id'] ?? '-' ),
					null !== ( $rses_raw['option_id'] ?? null ) ? (string) $rses_raw['option_id'] : '-',
					(int) ( $rses_raw['count'] ?? 0 )
				);
			}
		}

		return $rses_lines;
	}

	/**
	 * Append a signed-results JSON dump to PDF text lines.
	 *
	 * @param array<int,string>   $lines   Existing lines.
	 * @param array<string,mixed> $package Signed package (typically results-signed embed).
	 * @return array<int,string>
	 */
	public static function rses_pdf_append_signed_json( array $lines, array $package ): array {
		$lines[] = '';
		$lines[] = __( 'Signed results JSON', 'relatasoft-secure-election-suite' );
		$lines[] = str_repeat( '-', 40 );
		$lines[] = __( 'Schnorr-signed package (results). The downloadable signed-results.json also binds this PDF’s SHA-256.', 'relatasoft-secure-election-suite' );
		$lines[] = '';

		// ASCII JSON (\uXXXX for non-ASCII) so PDF WinAnsi encoding cannot corrupt the dump.
		$rses_json = wp_json_encode( $package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $rses_json ) ) {
			$lines[] = '{}';
			return $lines;
		}

		foreach ( explode( "\n", $rses_json ) as $rses_json_line ) {
			$lines[] = $rses_json_line;
		}

		return $lines;
	}

	/**
	 * Render humanized HTML (tables) for the certification screen.
	 *
	 * @param array<string,mixed> $humanized Humanized structure.
	 * @param string              $import_anchor Unique id suffix for forms.
	 */
	public static function rses_render_html( array $humanized, string $import_anchor = '' ): void {
		$rses_modes = self::rses_sort_modes();
		$rses_sort  = self::rses_normalize_sort( (string) ( $humanized['sort'] ?? self::RSES_SORT_COUNT_DESC ) );
		?>
		<div class="rses-human-results" data-rses-sort="<?php echo esc_attr( $rses_sort ); ?>">
			<div class="rses-human-results-toolbar">
				<label class="rses-field-label" for="rses_results_sort_<?php echo esc_attr( $import_anchor ); ?>">
					<?php esc_html_e( 'Order options by', 'relatasoft-secure-election-suite' ); ?>
				</label>
				<select
					id="rses_results_sort_<?php echo esc_attr( $import_anchor ); ?>"
					class="rses-results-sort"
					onchange="window.location.href=this.value;"
				>
					<?php foreach ( $rses_modes as $rses_mode => $rses_label ) : ?>
						<?php
						$rses_url = add_query_arg(
							array(
								'page'             => 'rses-certification',
								'rses_results_sort'=> $rses_mode,
							),
							admin_url( 'admin.php' )
						);
						if ( '' !== $import_anchor ) {
							$rses_url .= '#rses-cert-' . rawurlencode( $import_anchor );
						}
						?>
						<option value="<?php echo esc_url( $rses_url ); ?>" <?php selected( $rses_sort, $rses_mode ); ?>>
							<?php echo esc_html( $rses_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Default: most voted first within each question. Labels come from the imported ballot.', 'relatasoft-secure-election-suite' ); ?></p>
			</div>

			<?php if ( empty( $humanized['questions'] ) ) : ?>
				<p class="rses-empty"><?php esc_html_e( 'No decrypted option rows to display.', 'relatasoft-secure-election-suite' ); ?></p>
			<?php else : ?>
				<?php foreach ( $humanized['questions'] as $rses_q ) : ?>
					<section class="rses-human-question">
						<header class="rses-human-question-header">
							<h4 class="rses-human-question-title">
								<?php echo esc_html( (string) $rses_q['question_title'] ); ?>
								<small>#<?php echo esc_html( (string) (int) $rses_q['question_id'] ); ?></small>
							</h4>
							<?php if ( ! empty( $rses_q['question_description'] ) ) : ?>
								<p class="rses-panel-desc"><?php echo esc_html( (string) $rses_q['question_description'] ); ?></p>
							<?php endif; ?>
							<p class="rses-panel-desc">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: total votes */
										__( 'Total votes: %d', 'relatasoft-secure-election-suite' ),
										(int) ( $rses_q['total_votes'] ?? 0 )
									)
								);
								?>
							</p>
						</header>
						<div class="rses-table-wrap">
							<table class="rses-table rses-human-results-table">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Rank', 'relatasoft-secure-election-suite' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Option', 'relatasoft-secure-election-suite' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Votes', 'relatasoft-secure-election-suite' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Share', 'relatasoft-secure-election-suite' ); ?></th>
										<th scope="col">ID</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $rses_q['options'] as $rses_opt ) : ?>
										<tr>
											<td><?php echo esc_html( (string) (int) $rses_opt['rank'] ); ?></td>
											<td>
												<strong><?php echo esc_html( (string) $rses_opt['option_label'] ); ?></strong>
												<div class="rses-vote-bar" aria-hidden="true">
													<span style="width: <?php echo esc_attr( (string) min( 100, (float) $rses_opt['share_pct'] ) ); ?>%"></span>
												</div>
											</td>
											<td><strong><?php echo esc_html( number_format_i18n( (int) $rses_opt['count'] ) ); ?></strong></td>
											<td><?php echo esc_html( number_format_i18n( (float) $rses_opt['share_pct'], 2 ) ); ?>%</td>
											<td><code><?php echo esc_html( null !== $rses_opt['option_id'] ? (string) (int) $rses_opt['option_id'] : '—' ); ?></code></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</section>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
