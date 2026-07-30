<?php
/**
 * Empty / waiting state with optional animated pinwheel.
 *
 * @package VotoEletronicoTemaBase
 *
 * @var array{message?:string,animated?:bool} $args
 */

defined( 'ABSPATH' ) || exit;

use VotoEletronicoTemaBase\Branding;
use VotoEletronicoTemaBase\I18n;

$args     = is_array( $args ?? null ) ? $args : array();
$message  = isset( $args['message'] ) ? (string) $args['message'] : I18n::translate( 'Preparing the electoral workspace…' );
$animated = array_key_exists( 'animated', $args ) ? (bool) $args['animated'] : true;
?>
<div class="vetb-waiting">
	<?php Branding::render_pinwheel( $animated, 'waiting' ); ?>
	<p class="vetb-waiting__message"><?php echo esc_html( $message ); ?></p>
</div>
