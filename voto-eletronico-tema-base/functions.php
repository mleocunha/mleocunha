<?php
/**
 * Voto Eletrônico - Tema Base bootstrap.
 *
 * @package VotoEletronicoTemaBase
 */

defined( 'ABSPATH' ) || exit;

define( 'VETB_VERSION', '1.0.2' );
define( 'VETB_DIR', get_template_directory() );
define( 'VETB_URI', get_template_directory_uri() );

require_once VETB_DIR . '/includes/I18n.php';
require_once VETB_DIR . '/includes/Branding.php';
require_once VETB_DIR . '/includes/Journey.php';
require_once VETB_DIR . '/includes/Chrome.php';
require_once VETB_DIR . '/includes/Customizer.php';
require_once VETB_DIR . '/includes/Setup.php';

VotoEletronicoTemaBase\Setup::init();
VotoEletronicoTemaBase\I18n::init();
VotoEletronicoTemaBase\Branding::init();
VotoEletronicoTemaBase\Journey::init();
VotoEletronicoTemaBase\Chrome::init();
VotoEletronicoTemaBase\Customizer::init();
