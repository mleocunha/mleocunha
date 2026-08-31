<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http;

/**
 * Pedido HTTP mínimo (built-in server / nginx → index.php).
 */
final class Request {

	/**
	 * @param array<string,string> $get
	 * @param array<string,string> $post
	 * @param array<string,string> $cookies
	 * @param array<string,string> $server
	 * @param array<string,array<string,mixed>> $files
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly array $get,
		public readonly array $post,
		public readonly array $cookies,
		public readonly array $server,
		public readonly array $files = array(),
	) {}

	public static function fromGlobals(): self {
		$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$path = parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) ? rawurldecode( $path ) : '/';
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		/** @var array<string,array<string,mixed>> $files */
		$files = array();
		foreach ( $_FILES as $key => $info ) {
			if ( is_array( $info ) ) {
				$files[ (string) $key ] = $info;
			}
		}
		return new self(
			$method,
			$path,
			self::stringify( $_GET ),
			self::stringify( $_POST ),
			self::stringify( $_COOKIE ),
			self::stringify( $_SERVER ),
			$files,
		);
	}

	public function query( string $key, string $default = '' ): string {
		return $this->get[ $key ] ?? $default;
	}

	public function input( string $key, string $default = '' ): string {
		return $this->post[ $key ] ?? $default;
	}

	/**
	 * @param array<mixed,mixed> $src
	 * @return array<string,string>
	 */
	private static function stringify( array $src ): array {
		$out = array();
		foreach ( $src as $k => $v ) {
			if ( is_scalar( $v ) || null === $v ) {
				$out[ (string) $k ] = (string) $v;
			}
		}
		return $out;
	}
}
