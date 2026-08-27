<?php
/**
 * Conhecimento — documentação por persona (markdown).
 *
 * @package RelataSoft\SecureElectionSuite\Admin
 */

namespace RelataSoft\SecureElectionSuite\Admin;

use RelataSoft\SecureElectionSuite\I18n\Translator;
use RelataSoft\SecureElectionSuite\Painel\Core\PainelKernel;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\AccessPolicy;
use RelataSoft\SecureElectionSuite\Painel\Domain\Access\Persona;
use RelataSoft\SecureElectionSuite\Security\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * Renders filtered markdown docs under docs/conhecimento/.
 */
class KnowledgePage {

	public const SLUG = 'rses-knowledge';

	/**
	 * @return array<string,string> file stem => title
	 */
	public static function rses_catalog(): array {
		return array(
			'administrador'   => __( 'Administrador Eleitoral', 'relatasoft-secure-election-suite' ),
			'autoridade'      => __( 'Autoridade Eleitoral', 'relatasoft-secure-election-suite' ),
			'eleitor'         => __( 'Eleitor', 'relatasoft-secure-election-suite' ),
			'auditor'         => __( 'Auditor', 'relatasoft-secure-election-suite' ),
			'gestor'          => __( 'Gestor pelo Cliente', 'relatasoft-secure-election-suite' ),
			'implantacao-3wp' => __( 'Implantação E3 (3 WordPress)', 'relatasoft-secure-election-suite' ),
		);
	}

	/**
	 * Docs visible for the current persona.
	 *
	 * @return list<string> file stems
	 */
	public static function rses_docs_for_persona( Persona $persona ): array {
		return match ( $persona ) {
			Persona::AdministradorEleitoral => array_keys( self::rses_catalog() ),
			Persona::Gestor => array( 'gestor', 'implantacao-3wp' ),
			Persona::AutoridadeEleitoral => array( 'autoridade' ),
			Persona::Auditor => array( 'auditor' ),
			Persona::Eleitor => array( 'eleitor' ),
		};
	}

	public static function rses_render(): void {
		$kernel = PainelKernel::instance();
		$persona = $kernel ? $kernel->permissions->currentPersona() : Persona::Eleitor;
		$policy  = $kernel ? $kernel->accessPolicy : new AccessPolicy();

		if ( ! $policy->can( $persona, AccessPolicy::PERM_KNOWLEDGE_VIEW ) && ! Capability::rses_can_manage_election() ) {
			wp_die(
				esc_html__( 'Sem permissão para acessar ao Conhecimento.', 'relatasoft-secure-election-suite' ),
				esc_html__( 'Sem permissão', 'relatasoft-secure-election-suite' ),
				array( 'response' => 403 )
			);
		}

		$allowed = self::rses_docs_for_persona( $persona );
		$catalog = self::rses_catalog();
		$doc     = isset( $_GET['doc'] ) ? sanitize_key( wp_unslash( (string) $_GET['doc'] ) ) : ( $allowed[0] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $doc, $allowed, true ) ) {
			$doc = $allowed[0] ?? '';
		}

		// Mermaid CDN (jsDelivr).
		wp_enqueue_script(
			'rses-mermaid',
			'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js',
			array(),
			'10',
			true
		);
		wp_add_inline_script( 'rses-mermaid', "document.addEventListener('DOMContentLoaded',function(){if(window.mermaid){mermaid.initialize({startOnLoad:true,theme:'neutral'});}});", 'after' );

		$markdown = self::rses_read_doc( $doc );
		$html     = self::rses_markdown_to_html( $markdown );
		?>
		<div class="wrap rses-wrap rses-screen rses-knowledge" <?php echo Translator::rses_html_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<header class="rses-hero rses-hero--brand">
				<?php Brand::rses_render_hero_brand(); ?>
				<p class="rses-hero-kicker"><?php esc_html_e( 'Documentação', 'relatasoft-secure-election-suite' ); ?></p>
				<h1 class="rses-hero-title"><?php esc_html_e( 'Conhecimento', 'relatasoft-secure-election-suite' ); ?></h1>
				<p class="rses-hero-lead"><?php esc_html_e( 'Guias por perfil de uso do Painel de Controle Eleitoral.', 'relatasoft-secure-election-suite' ); ?></p>
			</header>

			<nav class="rses-knowledge-nav" aria-label="<?php esc_attr_e( 'Documentos', 'relatasoft-secure-election-suite' ); ?>">
				<ul>
					<?php foreach ( $allowed as $stem ) : ?>
						<li>
							<a class="<?php echo $stem === $doc ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&doc=' . rawurlencode( $stem ) ) ); ?>">
								<?php echo esc_html( $catalog[ $stem ] ?? $stem ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</nav>

			<section class="rses-panel rses-panel-card rses-knowledge-body">
				<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from local markdown via escaper helpers ?>
			</section>
		</div>
		<?php
	}

	private static function rses_read_doc( string $stem ): string {
		if ( '' === $stem || ! preg_match( '/^[a-z0-9\-]+$/', $stem ) ) {
			return '';
		}
		$path = RSES_PLUGIN_DIR . 'docs/conhecimento/' . $stem . '.md';
		if ( ! is_readable( $path ) ) {
			return __( 'Documento não encontrado.', 'relatasoft-secure-election-suite' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = (string) file_get_contents( $path );
		return $raw;
	}

	/**
	 * Minimal Markdown → HTML (headers, lists, code, links, images, mermaid fences).
	 */
	public static function rses_markdown_to_html( string $md ): string {
		$md = str_replace( array( "\r\n", "\r" ), "\n", $md );
		$parts = preg_split( '/(```[\w-]*\n[\s\S]*?```)/', $md, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			$parts = array( $md );
		}

		$out = '';
		foreach ( $parts as $part ) {
			if ( preg_match( '/^```(\w*)\n([\s\S]*?)```$/m', $part, $m ) ) {
				$lang = strtolower( (string) $m[1] );
				$code = rtrim( (string) $m[2], "\n" );
				if ( 'mermaid' === $lang ) {
					$out .= '<pre class="mermaid">' . esc_html( $code ) . '</pre>';
				} else {
					$out .= '<pre><code>' . esc_html( $code ) . '</code></pre>';
				}
				continue;
			}
			$out .= self::rses_md_block( $part );
		}
		return $out;
	}

	private static function rses_md_block( string $text ): string {
		$lines = explode( "\n", $text );
		$html  = '';
		$in_ul = false;
		$in_ol = false;
		$para  = array();

		$flush_para = static function () use ( &$para, &$html ): void {
			if ( empty( $para ) ) {
				return;
			}
			$html .= '<p>' . implode( ' ', $para ) . '</p>';
			$para  = array();
		};
		$close_lists = static function () use ( &$in_ul, &$in_ol, &$html ): void {
			if ( $in_ul ) {
				$html .= '</ul>';
				$in_ul = false;
			}
			if ( $in_ol ) {
				$html .= '</ol>';
				$in_ol = false;
			}
		};

		foreach ( $lines as $line ) {
			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $line, $hm ) ) {
				$flush_para();
				$close_lists();
				$level = strlen( $hm[1] );
				$html .= '<h' . $level . '>' . self::rses_inline( $hm[2] ) . '</h' . $level . '>';
				continue;
			}
			if ( preg_match( '/^\s*[-*]\s+(.+)$/', $line, $lm ) ) {
				$flush_para();
				if ( $in_ol ) {
					$html .= '</ol>';
					$in_ol = false;
				}
				if ( ! $in_ul ) {
					$html .= '<ul>';
					$in_ul = true;
				}
				$html .= '<li>' . self::rses_inline( $lm[1] ) . '</li>';
				continue;
			}
			if ( preg_match( '/^\s*\d+\.\s+(.+)$/', $line, $om ) ) {
				$flush_para();
				if ( $in_ul ) {
					$html .= '</ul>';
					$in_ul = false;
				}
				if ( ! $in_ol ) {
					$html .= '<ol>';
					$in_ol = true;
				}
				$html .= '<li>' . self::rses_inline( $om[1] ) . '</li>';
				continue;
			}
			if ( '' === trim( $line ) ) {
				$flush_para();
				$close_lists();
				continue;
			}
			$close_lists();
			$para[] = self::rses_inline( $line );
		}
		$flush_para();
		$close_lists();
		return $html;
	}

	private static function rses_inline( string $text ): string {
		$text = esc_html( $text );
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text ) ?? $text;
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text ) ?? $text;
		$text = preg_replace( '/\*([^*]+)\*/', '<em>$1</em>', $text ) ?? $text;
		$text = preg_replace(
			'/!\[([^\]]*)\]\(([^)]+)\)/',
			'<img src="$2" alt="$1" loading="lazy" />',
			$text
		) ?? $text;
		$text = preg_replace(
			'/\[([^\]]+)\]\(([^)]+)\)/',
			'<a href="$2" rel="noopener noreferrer">$1</a>',
			$text
		) ?? $text;
		return $text;
	}
}
