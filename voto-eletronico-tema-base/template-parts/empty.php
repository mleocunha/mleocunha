<?php
/**
 * Empty loop partial.
 *
 * @package VotoEletronicoTemaBase
 */

defined( 'ABSPATH' ) || exit;

use VotoEletronicoTemaBase\I18n;

get_template_part(
	'template-parts/waiting',
	null,
	array(
		'message'  => I18n::translate( 'No electoral content is published on this page yet.' ),
		'animated' => false,
	)
);
