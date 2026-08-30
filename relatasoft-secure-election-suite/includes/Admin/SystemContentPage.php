<?php
/**
 * Páginas e Posts — create / edit / duplicate under system management.
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Security\Capability;
use RelataSoft\SecureElectionSuite\Security\Nonce;

defined( 'ABSPATH' ) || exit;

/**
 * Content management façade (pages + posts) nested under Atualizar o Sistema.
 */
class SystemContentPage {

	public const SLUG = 'rses-system-content';

	public static function register(): void {
		add_action( 'admin_post_ve_content_create', array( self::class, 'handle_create' ) );
		add_action( 'admin_post_ve_content_duplicate', array( self::class, 'handle_duplicate' ) );
	}

	public static function render(): void {
		Capability::rses_require_admin();
		wp_enqueue_style( 've-painel-system' );

		$rses_tab = sanitize_key( (string) ( $_GET['ve_type'] ?? 'page' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $rses_tab, array( 'page', 'post' ), true ) ) {
			$rses_tab = 'page';
		}

		$rses_notice = isset( $_GET['ve_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ve_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rses_items  = get_posts(
			array(
				'post_type'              => $rses_tab,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 200,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		?>
		<div class="wrap rses-wrap rses-screen ve-system-page" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="ve-system-hero">
				<p class="ve-system-kicker"><?php esc_html_e( 'Gestão da plataforma', 'relatasoft-secure-election-suite' ); ?></p>
				<h1><?php esc_html_e( 'Pages and Posts', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="ve-system-lead">
					<?php esc_html_e( 'Create, edit, and duplicate site pages and posts from the electoral control panel — without exposing classic content menus.', 'relatasoft-secure-election-suite' ); ?>
				</p>
			</header>

			<?php if ( $rses_notice ) : ?>
				<div class="ve-system-notice"><?php echo esc_html( $rses_notice ); ?></div>
			<?php endif; ?>

			<nav class="ve-content-tabs" aria-label="<?php esc_attr_e( 'Content type', 'relatasoft-secure-election-suite' ); ?>">
				<a class="ve-btn <?php echo 'page' === $rses_tab ? 've-btn-primary' : 've-btn-ghost'; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&ve_type=page' ) ); ?>">
					<?php esc_html_e( 'Pages', 'relatasoft-secure-election-suite' ); ?>
				</a>
				<a class="ve-btn <?php echo 'post' === $rses_tab ? 've-btn-primary' : 've-btn-ghost'; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&ve_type=post' ) ); ?>">
					<?php esc_html_e( 'Posts', 'relatasoft-secure-election-suite' ); ?>
				</a>
			</nav>

			<section class="ve-system-card">
				<div class="ve-content-toolbar">
					<h2>
						<?php
						echo 'page' === $rses_tab
							? esc_html__( 'Pages', 'relatasoft-secure-election-suite' )
							: esc_html__( 'Posts', 'relatasoft-secure-election-suite' );
						?>
					</h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ve-system-inline-form">
						<input type="hidden" name="action" value="ve_content_create" />
						<input type="hidden" name="ve_type" value="<?php echo esc_attr( $rses_tab ); ?>" />
						<?php Nonce::rses_field( 've_content_create' ); ?>
						<button type="submit" class="ve-btn ve-btn-primary">
							<?php
							echo 'page' === $rses_tab
								? esc_html__( 'Create page', 'relatasoft-secure-election-suite' )
								: esc_html__( 'Create post', 'relatasoft-secure-election-suite' );
							?>
						</button>
					</form>
				</div>

				<?php if ( empty( $rses_items ) ) : ?>
					<p class="ve-system-ok"><?php esc_html_e( 'No items yet. Create the first one with the button above.', 'relatasoft-secure-election-suite' ); ?></p>
				<?php else : ?>
					<div class="ve-system-table-wrap">
						<table class="ve-system-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Title', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Status', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Modified', 'relatasoft-secure-election-suite' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'relatasoft-secure-election-suite' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rses_items as $rses_post ) : ?>
									<?php
									$rses_edit = get_edit_post_link( (int) $rses_post->ID, 'raw' );
									$rses_dup  = Nonce::rses_url(
										admin_url( 'admin-post.php?action=ve_content_duplicate&post_id=' . (int) $rses_post->ID ),
										've_content_duplicate'
									);
									?>
									<tr>
										<td>
											<strong><?php echo esc_html( get_the_title( $rses_post ) ?: __( '(no title)', 'relatasoft-secure-election-suite' ) ); ?></strong>
											<span class="ve-content-id">#<?php echo (int) $rses_post->ID; ?></span>
										</td>
										<td><?php echo esc_html( self::status_label( (string) $rses_post->post_status ) ); ?></td>
										<td><?php echo esc_html( get_post_modified_time( 'Y-m-d H:i', true, $rses_post ) ); ?></td>
										<td>
											<div class="ve-system-actions">
												<?php if ( is_string( $rses_edit ) && '' !== $rses_edit ) : ?>
													<a class="ve-btn ve-btn-ghost" href="<?php echo esc_url( $rses_edit ); ?>"><?php esc_html_e( 'Edit', 'relatasoft-secure-election-suite' ); ?></a>
												<?php endif; ?>
												<a class="ve-btn ve-btn-ghost" href="<?php echo esc_url( $rses_dup ); ?>"><?php esc_html_e( 'Duplicate', 'relatasoft-secure-election-suite' ); ?></a>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Create a draft page/post and open the editor.
	 */
	public static function handle_create(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_content_create' );

		$rses_type = sanitize_key( (string) ( $_POST['ve_type'] ?? 'page' ) );
		if ( ! in_array( $rses_type, array( 'page', 'post' ), true ) ) {
			$rses_type = 'page';
		}

		if ( 'page' === $rses_type && ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'Sem permissão para criar páginas.', 'relatasoft-secure-election-suite' ), 403 );
		}
		if ( 'post' === $rses_type && ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sem permissão para criar posts.', 'relatasoft-secure-election-suite' ), 403 );
		}

		$rses_title = 'page' === $rses_type
			? __( 'New page', 'relatasoft-secure-election-suite' )
			: __( 'New post', 'relatasoft-secure-election-suite' );

		$rses_id = wp_insert_post(
			array(
				'post_title'  => $rses_title,
				'post_status' => 'draft',
				'post_type'   => $rses_type,
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $rses_id ) ) {
			wp_safe_redirect(
				admin_url(
					'admin.php?page=' . self::SLUG . '&ve_type=' . rawurlencode( $rses_type ) . '&ve_notice=' . rawurlencode( $rses_id->get_error_message() )
				)
			);
			exit;
		}

		$rses_edit = get_edit_post_link( (int) $rses_id, 'raw' );
		if ( is_string( $rses_edit ) && '' !== $rses_edit ) {
			wp_safe_redirect( $rses_edit );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&ve_type=' . rawurlencode( $rses_type ) ) );
		exit;
	}

	/**
	 * Duplicate a page/post as draft and open the editor.
	 */
	public static function handle_duplicate(): void {
		Capability::rses_require_admin();
		Nonce::rses_verify_or_die( 've_content_duplicate' );

		$rses_id = absint( $_GET['post_id'] ?? $_POST['post_id'] ?? 0 );
		$rses_src = $rses_id > 0 ? get_post( $rses_id ) : null;
		if ( ! $rses_src || ! in_array( $rses_src->post_type, array( 'page', 'post' ), true ) ) {
			wp_die( esc_html__( 'Item not found.', 'relatasoft-secure-election-suite' ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $rses_id ) ) {
			wp_die( esc_html__( 'Sem permissão para duplicar este item.', 'relatasoft-secure-election-suite' ), 403 );
		}

		$rses_copy_title = sprintf(
			/* translators: %s: original title */
			__( '%s (copy)', 'relatasoft-secure-election-suite' ),
			(string) $rses_src->post_title
		);

		$rses_new_id = wp_insert_post(
			array(
				'post_title'     => $rses_copy_title,
				'post_content'   => $rses_src->post_content,
				'post_excerpt'   => $rses_src->post_excerpt,
				'post_status'    => 'draft',
				'post_type'      => $rses_src->post_type,
				'post_author'    => get_current_user_id(),
				'post_parent'    => (int) $rses_src->post_parent,
				'menu_order'     => (int) $rses_src->menu_order,
				'comment_status' => $rses_src->comment_status,
				'ping_status'    => $rses_src->ping_status,
			),
			true
		);

		if ( is_wp_error( $rses_new_id ) ) {
			wp_safe_redirect(
				admin_url(
					'admin.php?page=' . self::SLUG . '&ve_type=' . rawurlencode( $rses_src->post_type ) . '&ve_notice=' . rawurlencode( $rses_new_id->get_error_message() )
				)
			);
			exit;
		}

		$rses_taxonomies = get_object_taxonomies( $rses_src->post_type );
		foreach ( $rses_taxonomies as $rses_tax ) {
			$rses_terms = wp_get_object_terms( $rses_id, $rses_tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $rses_terms ) && ! empty( $rses_terms ) ) {
				wp_set_object_terms( (int) $rses_new_id, $rses_terms, $rses_tax );
			}
		}

		$rses_meta = get_post_meta( $rses_id );
		if ( is_array( $rses_meta ) ) {
			foreach ( $rses_meta as $rses_key => $rses_values ) {
				if ( ! is_string( $rses_key ) || '' === $rses_key || '_' === $rses_key[0] ) {
					// Skip private/internal meta by default (thumbnails etc. may use _).
					if ( ! in_array( $rses_key, array( '_thumbnail_id', '_wp_page_template' ), true ) ) {
						continue;
					}
				}
				foreach ( (array) $rses_values as $rses_value ) {
					add_post_meta( (int) $rses_new_id, $rses_key, maybe_unserialize( $rses_value ) );
				}
			}
		}

		$rses_edit = get_edit_post_link( (int) $rses_new_id, 'raw' );
		if ( is_string( $rses_edit ) && '' !== $rses_edit ) {
			wp_safe_redirect( $rses_edit );
			exit;
		}

		wp_safe_redirect(
			admin_url(
				'admin.php?page=' . self::SLUG . '&ve_type=' . rawurlencode( $rses_src->post_type ) . '&ve_notice=' . rawurlencode( __( 'Item duplicated.', 'relatasoft-secure-election-suite' ) )
			)
		);
		exit;
	}

	/**
	 * Localized status label.
	 */
	private static function status_label( string $status ): string {
		$map = array(
			'publish' => __( 'Published', 'relatasoft-secure-election-suite' ),
			'draft'   => __( 'Draft', 'relatasoft-secure-election-suite' ),
			'pending' => __( 'Pending', 'relatasoft-secure-election-suite' ),
			'private' => __( 'Private', 'relatasoft-secure-election-suite' ),
			'future'  => __( 'Scheduled', 'relatasoft-secure-election-suite' ),
		);
		return $map[ $status ] ?? $status;
	}
}
