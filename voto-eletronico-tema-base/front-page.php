<?php
/**
 * Front page — defers to welcome journey page when configured.
 *
 * @package VotoEletronicoTemaBase
 */

defined( 'ABSPATH' ) || exit;

use VotoEletronicoTemaBase\I18n;
use VotoEletronicoTemaBase\Journey;

$ids = Journey::page_ids();

if ( $ids['welcome'] > 0 ) {
	$welcome = get_post( $ids['welcome'] );
	if ( $welcome instanceof WP_Post && 'publish' === $welcome->post_status ) {
		get_header();
		?>
		<div class="vetb-canvas vetb-canvas--welcome">
			<article class="vetb-article">
				<div class="vetb-article__content entry-content">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core content filters apply.
					echo apply_filters( 'the_content', $welcome->post_content );
					?>
				</div>
			</article>
		</div>
		<?php
		get_footer();
		return;
	}
}

// Fallback: normal loop / empty state.
get_header();
?>
<div class="vetb-canvas vetb-canvas--home">
	<?php if ( have_posts() ) : ?>
		<?php while ( have_posts() ) : ?>
			<?php the_post(); ?>
			<article <?php post_class( 'vetb-article' ); ?>>
				<div class="vetb-article__content entry-content">
					<?php the_content(); ?>
				</div>
			</article>
		<?php endwhile; ?>
	<?php else : ?>
		<section class="vetb-empty">
			<?php get_template_part( 'template-parts/waiting', null, array( 'message' => I18n::translate( 'Configure the welcome page under Election Suite → Redirections.' ) ) ); ?>
		</section>
	<?php endif; ?>
</div>
<?php
get_footer();
