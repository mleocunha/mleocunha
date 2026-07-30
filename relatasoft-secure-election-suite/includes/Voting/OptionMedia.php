<?php
/**
 * Ballot option media attachments (image / audio / video).
 *
 * @package RelataSoft\SecureElectionSuite\Voting
 */

namespace RelataSoft\SecureElectionSuite\Voting;

use RelataSoft\SecureElectionSuite\Security\Escaper;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and renders WP media attached to ballot options.
 */
class OptionMedia {

	public const RSES_TYPE_IMAGE = 'image';
	public const RSES_TYPE_AUDIO = 'audio';
	public const RSES_TYPE_VIDEO = 'video';

	/**
	 * Allowed high-level media kinds.
	 *
	 * @var list<string>
	 */
	public const RSES_ALLOWED_TYPES = array(
		self::RSES_TYPE_IMAGE,
		self::RSES_TYPE_AUDIO,
		self::RSES_TYPE_VIDEO,
	);

	/**
	 * Sanitize an attachment ID; returns 0 when missing or not image/audio/video.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function rses_sanitize_attachment_id( int $attachment_id ): int {
		if ( $attachment_id <= 0 || ! get_post( $attachment_id ) ) {
			return 0;
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return 0;
		}

		return self::rses_classify_attachment( $attachment_id ) ? $attachment_id : 0;
	}

	/**
	 * Classify attachment as image, audio, or video.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null One of RSES_ALLOWED_TYPES or null.
	 */
	public static function rses_classify_attachment( int $attachment_id ): ?string {
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( '' === $mime ) {
			return null;
		}

		if ( 0 === strpos( $mime, 'image/' ) ) {
			return self::RSES_TYPE_IMAGE;
		}
		if ( 0 === strpos( $mime, 'audio/' ) ) {
			return self::RSES_TYPE_AUDIO;
		}
		if ( 0 === strpos( $mime, 'video/' ) ) {
			return self::RSES_TYPE_VIDEO;
		}

		return null;
	}

	/**
	 * Build metadata array for storage in metadata_json.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,mixed>
	 */
	public static function rses_metadata_from_attachment( int $attachment_id ): array {
		$attachment_id = self::rses_sanitize_attachment_id( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return array();
		}

		$type = self::rses_classify_attachment( $attachment_id );
		$mime = (string) get_post_mime_type( $attachment_id );

		return array(
			'attachment_id' => $attachment_id,
			'media_type'    => $type,
			'mime_type'     => $mime,
		);
	}

	/**
	 * Parse option metadata_json.
	 *
	 * @param object|string|null $option_or_json Option row or JSON string.
	 * @return array{attachment_id:int,media_type:string,mime_type:string,url:string,alt:string}
	 */
	public static function rses_parse( $option_or_json ): array {
		$empty = array(
			'attachment_id' => 0,
			'media_type'    => '',
			'mime_type'     => '',
			'url'           => '',
			'alt'           => '',
		);

		$json = '';
		$alt  = '';
		if ( is_object( $option_or_json ) ) {
			$json = (string) ( $option_or_json->metadata_json ?? '' );
			$alt  = (string) ( $option_or_json->option_label ?? '' );
		} elseif ( is_string( $option_or_json ) ) {
			$json = $option_or_json;
		}

		if ( '' === $json ) {
			return $empty;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return $empty;
		}

		$id = isset( $data['attachment_id'] ) ? absint( $data['attachment_id'] ) : 0;
		$id = self::rses_sanitize_attachment_id( $id );
		if ( $id <= 0 ) {
			return $empty;
		}

		$type = self::rses_classify_attachment( $id );
		$url  = Escaper::rses_attachment_url( $id );
		if ( ! $type || '' === $url ) {
			return $empty;
		}

		$thumb = '';
		if ( self::RSES_TYPE_IMAGE === $type ) {
			$src = wp_get_attachment_image_src( $id, 'medium' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$thumb = esc_url( $src[0] );
			}
		}

		return array(
			'attachment_id' => $id,
			'media_type'    => $type,
			'mime_type'     => (string) ( $data['mime_type'] ?? get_post_mime_type( $id ) ),
			'url'           => $url,
			'thumb'         => $thumb ?: $url,
			'alt'           => $alt !== '' ? $alt : (string) get_the_title( $id ),
		);
	}

	/**
	 * Whether option has usable media.
	 *
	 * @param object $option Option row.
	 */
	public static function rses_has_media( object $option ): bool {
		$meta = self::rses_parse( $option );
		return $meta['attachment_id'] > 0 && '' !== $meta['url'];
	}

	/**
	 * Render media HTML for booth or admin preview.
	 *
	 * @param object $option  Option row.
	 * @param string $context booth|admin.
	 * @return string Escaped HTML (or empty).
	 */
	public static function rses_render( object $option, string $context = 'booth' ): string {
		$meta = self::rses_parse( $option );
		if ( $meta['attachment_id'] <= 0 || '' === $meta['url'] ) {
			return '';
		}

		$type  = $meta['media_type'];
		$url   = $meta['url'];
		$alt   = $meta['alt'];
		$class = 'admin' === $context ? 'rses-option-media rses-option-media--admin' : 'rses-option-media rses-option-media--booth';

		ob_start();
		echo '<span class="' . esc_attr( $class . ' rses-option-media--' . $type ) . '">';

		if ( self::RSES_TYPE_IMAGE === $type ) {
			$src = ! empty( $meta['thumb'] ) ? $meta['thumb'] : $url;
			echo '<img class="rses-option-media-image" src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" />';
		} elseif ( self::RSES_TYPE_AUDIO === $type ) {
			echo '<audio class="rses-option-media-audio" controls preload="metadata" src="' . esc_url( $url ) . '"></audio>';
		} elseif ( self::RSES_TYPE_VIDEO === $type ) {
			echo '<video class="rses-option-media-video" controls preload="metadata" playsinline src="' . esc_url( $url ) . '"></video>';
		}

		echo '</span>';
		return (string) ob_get_clean();
	}

	/**
	 * Human label for media type.
	 *
	 * @param string $type Media type.
	 */
	public static function rses_type_label( string $type ): string {
		switch ( $type ) {
			case self::RSES_TYPE_IMAGE:
				return __( 'Photo', 'relatasoft-secure-election-suite' );
			case self::RSES_TYPE_AUDIO:
				return __( 'Audio', 'relatasoft-secure-election-suite' );
			case self::RSES_TYPE_VIDEO:
				return __( 'Video', 'relatasoft-secure-election-suite' );
			default:
				return __( 'Media', 'relatasoft-secure-election-suite' );
		}
	}
}
