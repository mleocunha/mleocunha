<?php
/**
 * Page template — electoral canvas.
 *
 * @package VotoEletronicoTemaBase
 */

defined( 'ABSPATH' ) || exit;

use VotoEletronicoTemaBase\Branding;
use VotoEletronicoTemaBase\Journey;

get_header();

$context = Journey::current_context();
?>

<div class="vetb-canvas vetb-canvas--<?php echo esc_attr( $context ?: 'page' ); ?>">
	<?php if ( 'thank_you' === $context ) : ?>
		<div class="vetb-formal-mark" aria-hidden="true">
			<?php Branding::render_pinwheel( false, 'formal' ); ?>
		</div>
	<?php endif; ?>

	<?php while ( have_posts() ) : ?>
		<?php the_post(); ?>
		<article <?php post_class( 'vetb-article' ); ?>>
			<div class="vetb-article__content entry-content">
				<?php the_content(); ?>
			</div>
		</article>
	<?php endwhile; ?>
</div>

<?php
get_footer();
