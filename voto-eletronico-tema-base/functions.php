<?php
/**
 * Voto Eletrônico - Tema Base bootstrap.
 *
 * @package VotoEletronicoTemaBase
 */

defined( 'ABSPATH' ) || exit;

define( 'VETB_VERSION', '1.0.1' );
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

// Ignora erros de SSL em requisições HTTP internas/loopback do WordPress
add_filter( 'https_local_ssl_verify_cb', '__return_false' );

// Força o cURL a confiar no loopback local sem timeout estrito
add_filter( 'http_request_args', function( $r, $url ) {
    if ( strpos( $url, 'votar.votoeletronico.com.br' ) !== false ) {
        $r['reject_unsafe_urls'] = true;
        $r['sslverify'] = false;
    }
    return $r;
}, 10, 2 );
