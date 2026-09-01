<?php
declare(strict_types=1);

/**
 * Entrada HTTP do pacote (raiz).
 *
 * Uso (um processo = um nó E3):
 *   VE_MODE=voting VE_DATA=/var/ve/voting php -S 10.42.0.1:8888 index.php
 *   php bin/ve-http --mode=voting --data=/var/ve/voting
 *
 * Nginx pode fazer proxy mantendo as URLs públicas (/login, /painel, /voto, …).
 */

$root = __DIR__;
$autoload = $root . '/vendor/autoload.php';
if ( ! is_readable( $autoload ) ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo "Missing vendor/autoload.php — run composer install.\n";
	exit( 1 );
}
require $autoload;

use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http\HttpKernel;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http\Request;
use RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\NodeRuntime;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Mode\SiteModes;

$mode = (string) ( getenv( 'RSES_MODE' ) ?: getenv( 'VE_MODE' ) ?: '' );
$data = (string) ( getenv( 'VE_DATA' ) ?: getenv( 'RSES_DATA' ) ?: '' );
$base = (string) ( getenv( 'VE_PUBLIC_BASE' ) ?: '' );
$cliente = (string) ( getenv( 'VE_CLIENTE' ) ?: 'piloto' );

if ( ! SiteModes::isValid( $mode ) ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo "Set RSES_MODE|VE_MODE to one of: " . implode( ', ', SiteModes::all() ) . "\n";
	exit( 1 );
}
if ( '' === $data ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo "Set VE_DATA|RSES_DATA to this node's data directory.\n";
	exit( 1 );
}

$node   = NodeRuntime::create( $mode, $data, $cliente, true, $base );
$kernel = new HttpKernel( $node, $root, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null );
$kernel->handle( Request::fromGlobals() )->send();
