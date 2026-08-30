<?php
/**
 * 404 — keep electoral chrome consistent.
 *
 * @package VotoEletronicoTemaBase
 */

defined( 'ABSPATH' ) || exit;

use VotoEletronicoTemaBase\I18n;

get_header();
?>
<div class="vetb-canvas vetb-canvas--error">
	<section class="vetb-empty">
		<?php
		get_template_part(
			'template-parts/waiting',
			null,
			array(
				'message' => I18n::translate( 'This page is not available in the electoral workspace.' ),
			)
		);
		?>
		<p class="vetb-empty__actions">
			<a class="vetb-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( I18n::translate( 'Return to start' ) ); ?></a>
		</p>
	</section>
</div>
<?php
get_footer();
