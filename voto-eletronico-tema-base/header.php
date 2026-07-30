<?php
/**
 * Minimal electoral header (logo only — no WP menus).
 *
 * @package VotoEletronicoTemaBase
 */

defined( 'ABSPATH' ) || exit;

use VotoEletronicoTemaBase\Branding;
use VotoEletronicoTemaBase\Customizer;
use VotoEletronicoTemaBase\I18n;
use VotoEletronicoTemaBase\Journey;

?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="vetb-html">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="vetb-skip-link" href="#vetb-content"><?php echo esc_html( I18n::translate( 'Skip to content' ) ); ?></a>

<div class="vetb-shell">
	<header class="vetb-topbar" role="banner">
		<div class="vetb-topbar__inner">
			<?php
			$context = Journey::current_context();
			if ( in_array( $context, array( 'booth' ), true ) ) {
				// Low cognitive load: pinwheel only in the voting booth.
				Branding::render_pinwheel( false, 'header' );
			} elseif ( 'thank_you' === $context && Customizer::formal_thank_you() ) {
				// Formal screens lead with the vertical lockup in the canvas; keep topbar quiet.
				Branding::render_pinwheel( false, 'header' );
			} else {
				// Common brand expression: horizontal lockup.
				Branding::render_lockup( 'header' );
			}

			if ( Customizer::show_topbar_label() && 'booth' !== $context ) :
				?>
				<p class="vetb-topbar__label"><?php echo esc_html( I18n::translate( 'Electronic voting' ) ); ?></p>
				<?php
			endif;
			?>
		</div>
	</header>

	<main id="vetb-content" class="vetb-main" role="main">
