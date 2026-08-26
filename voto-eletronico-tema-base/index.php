<?php
/**
 * Default fallback template.
 *
 * @package VotoEletronicoTemaBase
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="vetb-canvas">
	<?php if ( have_posts() ) : ?>
		<?php while ( have_posts() ) : ?>
			<?php the_post(); ?>
			<article <?php post_class( 'vetb-article' ); ?>>
				<?php if ( ! is_front_page() && ! has_shortcode( get_the_content(), 'rses_voter_welcome' ) && ! has_shortcode( get_the_content(), 'rses_voter_thank_you' ) && ! has_shortcode( get_the_content(), 'rses_voting_booth' ) ) : ?>
					<header class="vetb-article__header">
						<h1 class="vetb-article__title"><?php the_title(); ?></h1>
					</header>
				<?php endif; ?>
				<div class="vetb-article__content entry-content">
					<?php the_content(); ?>
				</div>
			</article>
		<?php endwhile; ?>
	<?php else : ?>
		<?php get_template_part( 'template-parts/empty' ); ?>
	<?php endif; ?>
</div>

<?php
get_footer();
