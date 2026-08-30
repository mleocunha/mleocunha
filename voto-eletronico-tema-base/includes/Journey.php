<?php
/**
 * Electoral journey awareness via plugin page IDs (slug-independent).
 *
 * @package VotoEletronicoTemaBase
 */

namespace VotoEletronicoTemaBase;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves welcome / booth / thank-you pages by ID from rses_settings.
 */
final class Journey {

	/**
	 * Hook registration.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_redirect_home' ) );
		add_filter( 'pre_get_document_title', array( self::class, 'document_title' ), 20 );
	}

	/**
	 * Plugin journey settings (by ID, never by slug).
	 *
	 * @return array{welcome:int,booth:int,thank_you:int}
	 */
	public static function page_ids(): array {
		$settings = Branding::plugin_settings();

		return array(
			'welcome'   => absint( $settings['welcome_page_id'] ?? 0 ),
			'booth'     => absint( $settings['booth_page_id'] ?? 0 ),
			'thank_you' => absint( $settings['thank_you_page_id'] ?? 0 ),
		);
	}

	/**
	 * Current page ID (queried object), 0 if none.
	 */
	public static function current_page_id(): int {
		if ( ! is_singular( 'page' ) ) {
			return 0;
		}
		return (int) get_queried_object_id();
	}

	/**
	 * Journey context slug for the current request.
	 *
	 * @return string welcome|booth|thank_you|election|''
	 */
	public static function current_context(): string {
		$page_id = self::current_page_id();
		$ids     = self::page_ids();

		if ( $page_id > 0 ) {
			if ( $ids['welcome'] === $page_id ) {
				return 'welcome';
			}
			if ( $ids['booth'] === $page_id ) {
				return 'booth';
			}
			if ( $ids['thank_you'] === $page_id ) {
				return 'thank_you';
			}

			$meta = (string) get_post_meta( $page_id, '_rses_journey_page', true );
			if ( in_array( $meta, array( 'welcome', 'thank_you', 'booth' ), true ) ) {
				return $meta;
			}

			$post = get_post( $page_id );
			if ( $post instanceof \WP_Post ) {
				if ( has_shortcode( $post->post_content, 'rses_voting_booth' ) ) {
					return 'booth';
				}
				if ( has_shortcode( $post->post_content, 'rses_voter_welcome' ) ) {
					return 'welcome';
				}
				if ( has_shortcode( $post->post_content, 'rses_voter_thank_you' ) ) {
					return 'thank_you';
				}
				if (
					has_shortcode( $post->post_content, 'rses_voter_receipt' )
					|| has_shortcode( $post->post_content, 'rses_election_status' )
				) {
					return 'election';
				}
			}
		}

		if ( is_front_page() && $ids['welcome'] > 0 ) {
			return 'welcome';
		}

		return '';
	}

	/**
	 * Front page → configured welcome page (by ID).
	 */
	public static function maybe_redirect_home(): void {
		if ( is_admin() || wp_doing_ajax() || is_preview() ) {
			return;
		}

		if ( ! is_front_page() ) {
			return;
		}

		$ids = self::page_ids();
		if ( $ids['welcome'] < 1 ) {
			return;
		}

		// Already viewing the welcome page as the front page — no redirect.
		if ( (int) get_queried_object_id() === $ids['welcome'] ) {
			return;
		}

		$show_on_front = get_option( 'show_on_front' );
		$page_on_front = (int) get_option( 'page_on_front' );
		if ( 'page' === $show_on_front && $page_on_front === $ids['welcome'] ) {
			return;
		}

		$url = get_permalink( $ids['welcome'] );
		if ( is_string( $url ) && '' !== $url ) {
			wp_safe_redirect( $url, 302 );
			exit;
		}
	}

	/**
	 * Document title hints for journey contexts.
	 *
	 * @param string $title Title.
	 */
	public static function document_title( string $title ): string {
		$map = array(
			'welcome'   => I18n::translate( 'Welcome and instructions' ),
			'booth'     => I18n::translate( 'Voting booth' ),
			'thank_you' => I18n::translate( 'Thank you for voting' ),
			'election'  => I18n::translate( 'Election' ),
		);

		$context = self::current_context();
		if ( isset( $map[ $context ] ) ) {
			return $map[ $context ];
		}

		return $title;
	}

	/**
	 * Permalink for a journey step by context key.
	 */
	public static function url_for( string $context ): string {
		$ids  = self::page_ids();
		$key  = 'thank_you' === $context ? 'thank_you' : $context;
		$id   = absint( $ids[ $key ] ?? 0 );
		if ( $id < 1 ) {
			return '';
		}
		$url = get_permalink( $id );
		return is_string( $url ) ? $url : '';
	}
}
