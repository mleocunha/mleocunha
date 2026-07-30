<?php
/**
 * Minimal footer (no WP widgets / site chrome).
 *
 * @package VotoEletronicoTemaBase
 */

defined( 'ABSPATH' ) || exit;

use VotoEletronicoTemaBase\I18n;
use VotoEletronicoTemaBase\Journey;

?>
	</main>

	<footer class="vetb-foot" role="contentinfo">
		<div class="vetb-foot__inner">
			<?php if ( 'booth' !== Journey::current_context() ) : ?>
				<p class="vetb-foot__note"><?php echo esc_html( I18n::translate( 'Secure electronic voting workspace' ) ); ?></p>
			<?php endif; ?>
		</div>
	</footer>
</div>

<?php wp_footer(); ?>
</body>
</html>
