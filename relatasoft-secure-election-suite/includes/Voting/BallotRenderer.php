<?php
/**
 * Ballot HTML renderer.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Security\Nonce;
use RelataSoft\SecureElectionSuite\I18n\Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Renders ballot forms for frontend voting.
 */
class BallotRenderer {

	/**
	 * Render full ballot form.
	 *
	 * @param int $election_id Election ID.
	 * @param int $round_id    Round ID.
	 * @return string HTML.
	 */
	public static function rses_render( int $election_id, int $round_id ): string {
		$rses_round = ElectionRepository::rses_get_round( $round_id );

		if ( ! $rses_round || 'open' !== $rses_round->status ) {
			return '<div class="rses-booth" ' . Translator::rses_html_attrs() . '><div class="rses-message rses-message-warning">' . esc_html__( 'Voting is not currently open.', 'relatasoft-secure-election-suite' ) . '</div></div>';
		}

		$rses_election  = ElectionRepository::rses_get( $election_id );
		$rses_questions = ElectionRepository::rses_get_questions( $round_id );

		if ( empty( $rses_questions ) ) {
			return '<div class="rses-booth" ' . Translator::rses_html_attrs() . '><div class="rses-message rses-message-warning">' . esc_html__( 'No ballot questions configured.', 'relatasoft-secure-election-suite' ) . '</div></div>';
		}

		ob_start();
		?>
		<div
			class="rses-booth rses-booth-ballot"
			data-rses-booth="ballot"
			data-rses-election-id="<?php echo esc_attr( (string) $election_id ); ?>"
			data-rses-round-id="<?php echo esc_attr( (string) $round_id ); ?>"
			<?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		>
			<header class="rses-booth-header">
				<p class="rses-booth-kicker"><?php esc_html_e( 'Secure voting booth', 'relatasoft-secure-election-suite' ); ?></p>
				<?php if ( $rses_election ) : ?>
					<h2 class="rses-booth-title"><?php echo esc_html( $rses_election->title ); ?></h2>
				<?php else : ?>
					<h2 class="rses-booth-title"><?php esc_html_e( 'Cast your encrypted ballot', 'relatasoft-secure-election-suite' ); ?></h2>
				<?php endif; ?>
				<p class="rses-booth-lead"><?php esc_html_e( 'Select your choices below. Your ballot is encrypted on the server; plaintext selections are never stored.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<form
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				class="rses-ballot-form"
				id="<?php echo esc_attr( 'rses-ballot-form-' . (int) $round_id ); ?>"
				data-rses-ballot-form="1"
				data-rses-election-id="<?php echo esc_attr( (string) $election_id ); ?>"
				data-rses-round-id="<?php echo esc_attr( (string) $round_id ); ?>"
			>
				<?php Nonce::rses_field( Nonce::RSES_ACTION_VOTE_CAST ); ?>
				<input type="hidden" name="action" value="rses_cast_vote" />
				<input type="hidden" name="election_id" value="<?php echo esc_attr( (string) $election_id ); ?>" />
				<input type="hidden" name="round_id" value="<?php echo esc_attr( (string) $round_id ); ?>" />

				<div class="rses-questions">
					<?php
					$rses_q_index = 0;
					foreach ( $rses_questions as $rses_question ) :
						++$rses_q_index;
						$rses_options = ElectionRepository::rses_get_options( (int) $rses_question->id );
						$rses_name    = 'rses_ballot[question_' . (int) $rses_question->id . ']';
						$rses_type    = $rses_question->question_type;
						$rses_multi   = ( 'multiple_choice' === $rses_type );
						$rses_min     = (int) $rses_question->min_choices;
						$rses_max     = (int) $rses_question->max_choices;
						?>
						<fieldset
							class="rses-question"
							data-rses-question="<?php echo esc_attr( (string) (int) $rses_question->id ); ?>"
							data-rses-question-type="<?php echo esc_attr( (string) $rses_type ); ?>"
							data-rses-min="<?php echo esc_attr( (string) $rses_min ); ?>"
							data-rses-max="<?php echo esc_attr( (string) $rses_max ); ?>"
						>
							<legend class="rses-question-title">
								<span class="rses-question-number"><?php echo esc_html( (string) $rses_q_index ); ?></span>
								<span class="rses-question-text"><?php echo esc_html( $rses_question->question_title ); ?></span>
							</legend>
							<?php if ( $rses_question->question_description ) : ?>
								<p class="rses-question-desc"><?php echo esc_html( $rses_question->question_description ); ?></p>
							<?php endif; ?>

							<?php if ( 'numeric' === $rses_type ) : ?>
								<label class="rses-numeric-field">
									<span class="rses-numeric-label"><?php esc_html_e( 'Enter a number', 'relatasoft-secure-election-suite' ); ?></span>
									<input type="number" class="rses-numeric-input" name="<?php echo esc_attr( $rses_name ); ?>[]" min="0" required />
								</label>
							<?php else : ?>
								<div class="rses-choices" role="group" aria-label="<?php echo esc_attr( $rses_question->question_title ); ?>">
									<?php foreach ( $rses_options as $rses_option ) : ?>
										<?php
										$rses_oid   = (int) $rses_option->id;
										$rses_input = $rses_multi ? 'checkbox' : 'radio';
										$rses_id    = 'rses-choice-' . (int) $rses_question->id . '-' . $rses_oid;
										?>
										<label class="rses-choice" for="<?php echo esc_attr( $rses_id ); ?>" tabindex="0">
											<span class="rses-choice-control">
												<input
													class="rses-choice-input"
													type="<?php echo esc_attr( $rses_input ); ?>"
													name="<?php echo esc_attr( $rses_name ); ?>[]"
													id="<?php echo esc_attr( $rses_id ); ?>"
													value="<?php echo esc_attr( (string) $rses_oid ); ?>"
													data-rses-option-id="<?php echo esc_attr( (string) $rses_oid ); ?>"
													<?php echo $rses_multi ? '' : 'required'; ?>
												/>
												<span class="rses-choice-selector rses-choice-selector-<?php echo $rses_multi ? 'multiple' : 'single'; ?>" aria-hidden="true">
													<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" focusable="false">
														<path d="M9 15.2 4.8 11 2.4 13.4 9 20l13-13-2.4-2.4z" fill="currentColor"/>
													</svg>
												</span>
											</span>
											<span class="rses-choice-body">
												<?php
												// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- OptionMedia returns escaped HTML.
												echo OptionMedia::rses_render( $rses_option, 'booth' );
												?>
												<span class="rses-choice-label"><?php echo esc_html( $rses_option->option_label ); ?></span>
											</span>
										</label>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</fieldset>
					<?php endforeach; ?>
				</div>

				<div class="rses-ballot-footer">
					<p class="rses-ballot-review-notice"><?php esc_html_e( 'Review your selections before submitting. Your vote cannot be changed after casting.', 'relatasoft-secure-election-suite' ); ?></p>
					<button type="submit" class="rses-submit-vote"><?php esc_html_e( 'Cast Encrypted Vote', 'relatasoft-secure-election-suite' ); ?></button>
				</div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
