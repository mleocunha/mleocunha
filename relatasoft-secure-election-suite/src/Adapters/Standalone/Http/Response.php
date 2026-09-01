<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\Standalone\Http;

/**
 * Resposta HTTP mínima (HTML, redirect, JSON ou stream NDJSON).
 */
final class Response {

	/**
	 * @param array<string,string> $headers
	 * @param (callable():void)|null $streamer
	 */
	public function __construct(
		public readonly string $body,
		public readonly int $status = 200,
		public readonly array $headers = array( 'Content-Type' => 'text/html; charset=UTF-8' ),
		public readonly mixed $streamer = null,
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

	/** @param array<string,mixed> $data */
	public static function json( array $data, int $status = 200 ): self {
		$json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return new self(
			is_string( $json ) ? $json : '{}',
			$status,
			array( 'Content-Type' => 'application/json; charset=UTF-8' )
		);
	}

	/**
	 * @param callable():void $streamer
	 */
	public static function ndjsonStream( callable $streamer, int $status = 200 ): self {
		return new self(
			'',
			$status,
			array(
				'Content-Type'      => 'application/x-ndjson; charset=UTF-8',
				'Cache-Control'     => 'no-cache, no-store',
				'X-Accel-Buffering' => 'no',
			),
			$streamer
		);
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
		if ( is_callable( $this->streamer ) ) {
			while ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			( $this->streamer )();
			return;
		}
		echo $this->body;
	}
}
