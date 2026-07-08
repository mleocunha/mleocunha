<?php
/**
 * Ballot HTML renderer.
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Security\Escaper;
use RelataSoft\SecureElectionSuite\Security\Nonce;

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
			return '<p class="rses-notice">' . esc_html__( 'Voting is not currently open.', 'relatasoft-secure-election-suite' ) . '</p>';
		}

		$rses_questions = ElectionRepository::rses_get_questions( $round_id );

		if ( empty( $rses_questions ) ) {
			return '<p>' . esc_html__( 'No ballot questions configured.', 'relatasoft-secure-election-suite' ) . '</p>';
		}

		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-ballot-form" id="rses-ballot-form">
			<?php Nonce::rses_field( Nonce::RSES_ACTION_VOTE_CAST ); ?>
			<input type="hidden" name="action" value="rses_cast_vote" />
			<input type="hidden" name="election_id" value="<?php echo esc_attr( (string) $election_id ); ?>" />
			<input type="hidden" name="round_id" value="<?php echo esc_attr( (string) $round_id ); ?>" />

			<?php foreach ( $rses_questions as $rses_question ) : ?>
				<fieldset class="rses-ballot-question">
					<legend><strong><?php echo esc_html( $rses_question->question_title ); ?></strong></legend>
					<?php if ( $rses_question->question_description ) : ?>
						<p class="rses-question-desc"><?php echo esc_html( $rses_question->question_description ); ?></p>
					<?php endif; ?>

					<?php
					$rses_options = ElectionRepository::rses_get_options( (int) $rses_question->id );
					$rses_name    = 'rses_ballot[question_' . (int) $rses_question->id . ']';
					$rses_type    = $rses_question->question_type;

					if ( 'numeric' === $rses_type ) :
						?>
						<input type="number" name="<?php echo esc_attr( $rses_name ); ?>[]" min="0" required />
					<?php elseif ( 'multiple_choice' === $rses_type ) : ?>
						<?php foreach ( $rses_options as $rses_option ) : ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $rses_name ); ?>[]" value="<?php echo esc_attr( (string) $rses_option->id ); ?>" />
								<?php echo esc_html( $rses_option->option_label ); ?>
							</label><br />
						<?php endforeach; ?>
					<?php else : ?>
						<?php foreach ( $rses_options as $rses_option ) : ?>
							<label>
								<input type="radio" name="<?php echo esc_attr( $rses_name ); ?>[]" value="<?php echo esc_attr( (string) $rses_option->id ); ?>" required />
								<?php echo esc_html( $rses_option->option_label ); ?>
							</label><br />
						<?php endforeach; ?>
					<?php endif; ?>
				</fieldset>
			<?php endforeach; ?>

			<p class="rses-ballot-review-notice"><?php esc_html_e( 'Review your selections before submitting. Your vote cannot be changed after casting.', 'relatasoft-secure-election-suite' ); ?></p>
			<button type="submit" class="rses-submit-vote button button-primary"><?php esc_html_e( 'Cast Encrypted Vote', 'relatasoft-secure-election-suite' ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}
