<?php
/**
 * Shortcode [enviar_redefinicao_senha] — send WP password-reset email to the logged-in user.
 *
 * @package RelataSoft\SecureElectionSuite\Frontend
 */

namespace RelataSoft\SecureElectionSuite\Frontend;

use RelataSoft\SecureElectionSuite\I18n\LocaleResolver;
use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Password reset request UI + plain-text mail customization for PoC automation.
 */
class PasswordResetShortcode {

	public const RSES_SHORTCODE     = 'enviar_redefinicao_senha';
	public const RSES_ACTION        = 'rses_enviar_redefinicao_senha';
	public const RSES_NONCE_ACTION  = 'rses_enviar_redefinicao_senha';
	public const RSES_QUERY_STATUS  = 'redefinicao-senha';
	public const RSES_LOCALE_FIELD  = 'rses_poc_mail_locale';

	/**
	 * English msgid for the reset mail subject (catalogs translate per locale).
	 */
	public const RSES_MAIL_SUBJECT = 'Elector Password Reset';

	/**
	 * Register hooks and shortcode.
	 */
	public static function register(): void {
		add_shortcode( self::RSES_SHORTCODE, array( self::class, 'rses_render_shortcode' ) );
		add_action( 'admin_post_' . self::RSES_ACTION, array( self::class, 'rses_handle_send' ) );
		add_action( 'admin_post_nopriv_' . self::RSES_ACTION, array( self::class, 'rses_handle_send_nopriv' ) );

		add_filter( 'retrieve_password_title', array( self::class, 'rses_filter_retrieve_title' ), 10, 3 );
		add_filter( 'retrieve_password_message', array( self::class, 'rses_filter_retrieve_message' ), 10, 4 );
		add_filter( 'wp_mail_content_type', array( self::class, 'rses_filter_mail_content_type' ) );
	}

	/**
	 * Render shortcode. Available for any logged-in user; guests get lost-password link.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return string
	 */
	public static function rses_render_shortcode( array $atts = array() ): string {
		unset( $atts );

		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<p class="rses-password-reset" data-rses-password-reset="guest" %1$s><a class="rses-password-reset-link" href="%2$s">%3$s</a></p>',
				Translator::rses_html_attrs(),
				esc_url( wp_lostpassword_url() ),
				esc_html__( 'Recover my password', 'relatasoft-secure-election-suite' )
			);
		}

		$rses_status = isset( $_GET[ self::RSES_QUERY_STATUS ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET[ self::RSES_QUERY_STATUS ] ) )
			: '';

		$rses_message = '';
		if ( 'enviada' === $rses_status ) {
			$rses_message = '<p class="rses-password-reset-success" data-rses-password-reset-status="enviada">' .
				esc_html__( 'The reset email was sent. Check your inbox and spam folder.', 'relatasoft-secure-election-suite' ) .
				'</p>';
		} elseif ( 'erro' === $rses_status ) {
			$rses_message = '<p class="rses-password-reset-error" data-rses-password-reset-status="erro">' .
				esc_html__( 'Could not send the reset email. Try again or contact support.', 'relatasoft-secure-election-suite' ) .
				'</p>';
		}

		$rses_locale = get_user_locale( get_current_user_id() );

		ob_start();
		?>
		<div
			class="rses-password-reset"
			data-rses-password-reset="form"
			data-rses-user-locale="<?php echo esc_attr( $rses_locale ); ?>"
			<?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		>
			<?php echo $rses_message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with escaping. ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rses-password-reset-form" id="rses-password-reset-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::RSES_ACTION ); ?>" />
				<input type="hidden" name="<?php echo esc_attr( self::RSES_LOCALE_FIELD ); ?>" id="rses-poc-mail-locale" value="" />
				<?php Nonce::rses_field( self::RSES_NONCE_ACTION ); ?>
				<button type="submit" class="rses-password-reset-submit" data-rses-password-reset-submit="1">
					<?php esc_html_e( 'Receive email to reset my password', 'relatasoft-secure-election-suite' ); ?>
				</button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Guests posting the form are sent to lost-password.
	 */
	public static function rses_handle_send_nopriv(): void {
		wp_safe_redirect( wp_lostpassword_url() );
		exit;
	}

	/**
	 * Process reset-email request for the current user.
	 */
	public static function rses_handle_send(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_lostpassword_url() );
			exit;
		}

		Nonce::rses_verify_or_die( self::RSES_NONCE_ACTION );

		$rses_locale = isset( $_POST[ self::RSES_LOCALE_FIELD ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( $_POST[ self::RSES_LOCALE_FIELD ] ) )
			: '';
		$rses_locale = self::rses_normalize_locale( $rses_locale );
		if ( '' === $rses_locale ) {
			$rses_locale = self::rses_normalize_locale( get_user_locale( get_current_user_id() ) );
		}
		if ( '' === $rses_locale ) {
			$rses_locale = self::rses_normalize_locale( get_locale() );
		}
		if ( '' === $rses_locale ) {
			$rses_locale = 'en_US';
		}

		// Used by mail filters during retrieve_password() in this request.
		$GLOBALS['rses_poc_mail_locale'] = $rses_locale;

		$rses_user   = wp_get_current_user();
		$rses_result = retrieve_password( $rses_user->user_login );

		unset( $GLOBALS['rses_poc_mail_locale'] );

		$rses_origin = wp_get_referer();
		if ( ! $rses_origin ) {
			$rses_origin = home_url( '/' );
		}

		$rses_status = is_wp_error( $rses_result ) ? 'erro' : 'enviada';
		$rses_dest   = add_query_arg( self::RSES_QUERY_STATUS, $rses_status, $rses_origin );

		wp_safe_redirect( $rses_dest );
		exit;
	}

	/**
	 * Customize reset mail subject for PoC / elector wording.
	 *
	 * @param string  $title      Default title.
	 * @param string  $user_login User login.
	 * @param \WP_User $user      User.
	 */
	public static function rses_filter_retrieve_title( string $title, string $user_login, $user ): string {
		unset( $title, $user_login );
		$rses_locale = self::rses_mail_locale_for_user( $user );
		return self::rses_translate_for_locale( self::RSES_MAIL_SUBJECT, $rses_locale );
	}

	/**
	 * Plain-text reset message (no HTML).
	 *
	 * @param string  $message    Default message.
	 * @param string  $key        Reset key.
	 * @param string  $user_login User login.
	 * @param \WP_User $user      User.
	 */
	public static function rses_filter_retrieve_message( string $message, string $key, string $user_login, $user ): string {
		unset( $message );
		$rses_locale = self::rses_mail_locale_for_user( $user );
		$rses_site   = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$rses_link   = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user_login ),
			'login'
		);

		$rses_line1 = self::rses_translate_for_locale(
			'A password reset was requested for your elector account on %s.',
			$rses_locale
		);
		$rses_line2 = self::rses_translate_for_locale(
			'Open the following address to choose a new password:',
			$rses_locale
		);
		$rses_line3 = self::rses_translate_for_locale(
			'If you did not request this, ignore this message.',
			$rses_locale
		);

		return sprintf( $rses_line1, $rses_site ) . "\r\n\r\n" .
			$rses_line2 . "\r\n\r\n" .
			$rses_link . "\r\n\r\n" .
			$rses_line3 . "\r\n";
	}

	/**
	 * Force text/plain while our PoC mail locale is active for this request.
	 *
	 * @param string $content_type Content type.
	 */
	public static function rses_filter_mail_content_type( string $content_type ): string {
		if ( ! empty( $GLOBALS['rses_poc_mail_locale'] ) ) {
			return 'text/plain';
		}
		return $content_type;
	}

	/**
	 * @param \WP_User|false $user User.
	 */
	private static function rses_mail_locale_for_user( $user ): string {
		if ( ! empty( $GLOBALS['rses_poc_mail_locale'] ) && is_string( $GLOBALS['rses_poc_mail_locale'] ) ) {
			$rses_forced = self::rses_normalize_locale( $GLOBALS['rses_poc_mail_locale'] );
			if ( '' !== $rses_forced ) {
				return $rses_forced;
			}
		}

		if ( $user instanceof \WP_User ) {
			$rses_user_locale = self::rses_normalize_locale( get_user_locale( $user ) );
			if ( '' !== $rses_user_locale ) {
				return $rses_user_locale;
			}
		}

		$rses_site = self::rses_normalize_locale( get_locale() );
		return '' !== $rses_site ? $rses_site : 'en_US';
	}

	/**
	 * @param string $locale Raw locale.
	 */
	private static function rses_normalize_locale( string $locale ): string {
		$locale = str_replace( '-', '_', trim( $locale ) );
		if ( '' === $locale ) {
			return '';
		}
		if ( in_array( $locale, LocaleResolver::RSES_SUPPORTED, true ) ) {
			return $locale;
		}
		// Allow short forms like "en" → en_US when listed.
		foreach ( LocaleResolver::RSES_SUPPORTED as $rses_supported ) {
			if ( 0 === strcasecmp( $locale, $rses_supported ) ) {
				return $rses_supported;
			}
		}
		return '';
	}

	/**
	 * Translate msgid using a specific catalog file (not the current request locale).
	 *
	 * @param string $msgid  Source string.
	 * @param string $locale Locale code.
	 */
	public static function rses_translate_for_locale( string $msgid, string $locale ): string {
		$locale = self::rses_normalize_locale( $locale );
		if ( '' === $locale || 'en_US' === $locale || 'en' === $locale ) {
			return $msgid;
		}

		$file = RSES_PLUGIN_DIR . 'languages/catalogs/' . $locale . '.json';
		if ( ! is_readable( $file ) ) {
			return $msgid;
		}

		$raw = file_get_contents( $file );
		if ( false === $raw ) {
			return $msgid;
		}

		$map = json_decode( $raw, true );
		if ( ! is_array( $map ) ) {
			return $msgid;
		}

		if ( isset( $map[ $msgid ] ) && is_string( $map[ $msgid ] ) && '' !== $map[ $msgid ] ) {
			return $map[ $msgid ];
		}

		return $msgid;
	}
}
