<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http;

/**
 * Resposta HTTP mínima.
 */
final class Response {

	/**
	 * @param array<string,string> $headers
	 */
	public function __construct(
		public readonly string $body,
		public readonly int $status = 200,
		public readonly array $headers = array( 'Content-Type' => 'text/html; charset=UTF-8' ),
	) {}

	public static function html( string $body, int $status = 200 ): self {
		return new self( $body, $status, array( 'Content-Type' => 'text/html; charset=UTF-8' ) );
	}

	public static function redirect( string $location, int $status = 302 ): self {
		return new self( '', $status, array( 'Location' => $location ) );
	}

	public static function text( string $body, int $status = 200, string $type = 'text/plain; charset=UTF-8' ): self {
		return new self( $body, $status, array( 'Content-Type' => $type ) );
	}

	public static function file( string $absolutePath, string $downloadName, string $mime = 'application/octet-stream' ): self {
		if ( ! is_readable( $absolutePath ) ) {
			return self::text( 'Not found', 404 );
		}
		$body = (string) file_get_contents( $absolutePath );
		return new self(
			$body,
			200,
			array(
				'Content-Type'        => $mime,
				'Content-Length'      => (string) strlen( $body ),
				'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
			)
		);
	}

	public function send(): void {
		http_response_code( $this->status );
		foreach ( $this->headers as $k => $v ) {
			header( $k . ': ' . $v );
		}
		echo $this->body;
	}
}
