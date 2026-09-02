<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Adapters\WordPress\System;

use ZipArchive;

/**
 * Becape completo do sítio (ficheiros + base de dados) e restauração (C3).
 *
 * “Becape” = cópia de segurança operacional da instalação (não confundir com
 * courier de material criptográfico entre os 3 sítios).
 *
 * Guards puros (sem boot WP) no topo da classe — testáveis em PHPUnit:
 * {@see isValidManifest}, {@see isSafeBecapeBasename}, {@see isCorePluginBasename}.
 */
final class BecapeService {

	/** Nome do JSON de manifesto dentro do ZIP de becape. */
	public const MANIFEST_NAME = 've-becape-manifest.json';

	/**
	 * Identificador de formato do manifesto.
	 *
	 * Restauração recusa ZIPs cujo `format` não seja exactamente este valor
	 * (evita aplicar dumps de versões/formatos desconhecidos).
	 */
	public const MANIFEST_FORMAT = 've-becape-v1';

	/**
	 * Validar a forma mínima do manifesto antes de restaurar (C3).
	 *
	 * Exige `format === ve-becape-v1` e `created_utc` string (timestamp ISO).
	 * Função pura: sem I/O nem WordPress — usada em unit tests e em restore.
	 *
	 * @param array<string,mixed> $manifest Conteúdo já descodificado do JSON.
	 */
	public static function isValidManifest( array $manifest ): bool {
		return ( $manifest['format'] ?? '' ) === self::MANIFEST_FORMAT
			&& isset( $manifest['created_utc'] )
			&& is_string( $manifest['created_utc'] );
	}

	/**
	 * Aceitar só nomes de ficheiro canónicos sob o directório de becape.
	 *
	 * Protege download/delete contra path traversal (`../`), null bytes e
	 * nomes inventados (`evil.zip`). O padrão é:
	 * `becape-voto-eletronico-YYYYMMDD-HHMMSS.zip`.
	 */
	public static function isSafeBecapeBasename( string $name ): bool {
		// basename após remover separadores perigosos — nunca confiar no input cru.
		$name = basename( str_replace( array( '\\', "\0" ), '', $name ) );
		if ( '' === $name || str_contains( $name, '..' ) ) {
			return false;
		}
		return (bool) preg_match( '/^becape-voto-eletronico-[0-9]{8}-[0-9]{6}\.zip$/', $name );
	}

	/**
	 * O basename do plugin é o núcleo desta suíte?
	 *
	 * Usado por Módulos do Sistema para impedir `delete_plugins` do próprio
	 * `relatasoft-secure-election-suite/` (auto-remoção do Painel).
	 */
	public static function isCorePluginBasename( string $plugin_file ): bool {
		$file = str_replace( '\\', '/', $plugin_file );
		return str_starts_with( $file, 'relatasoft-secure-election-suite/' );
	}

	/**
	 * Directório de armazenamento dos ZIPs de becape (fora da web).
	 *
	 * Cria `uploads/ve-becape/` se necessário e escreve `.htaccess` Deny
	 * para Apache não servir os ficheiros por URL directa.
	 */
	public static function storageDir(): string {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 've-becape';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Negar acesso HTTP directo aos ZIPs (Apache); Nginx deve espelhar em ops.
		$ht = $dir . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $ht, "Deny from all\n" );
		}
		return $dir;
	}

	/**
	 * @return array{ok:bool,path?:string,error?:string,bytes?:int}
	 */
	public static function createBecape(): array {
		if ( ! class_exists( ZipArchive::class ) ) {
			return array( 'ok' => false, 'error' => 'A extensão ZipArchive do PHP é necessária.' );
		}

		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '512M' );

		$dir      = self::storageDir();
		$stamp    = gmdate( 'Ymd-His' );
		$basename = 'becape-voto-eletronico-' . $stamp . '.zip';
		$zip_path = $dir . '/' . $basename;
		$sql_path = $dir . '/database-' . $stamp . '.sql';

		$sql = self::exportDatabase();
		if ( '' === $sql ) {
			return array( 'ok' => false, 'error' => 'Falha ao exportar a base de dados.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $sql_path, $sql ) ) {
			return array( 'ok' => false, 'error' => 'Não foi possível gravar o dump da base de dados.' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return array( 'ok' => false, 'error' => 'Não foi possível criar o arquivo de becape.' );
		}

		$manifest = array(
			'format'         => self::MANIFEST_FORMAT,
			'created_utc'    => gmdate( 'c' ),
			'product'        => 'Voto Eletrônico by RelataSoft',
			'plugin_version' => defined( 'RSES_VERSION' ) ? RSES_VERSION : '',
			'platform_version' => get_bloginfo( 'version' ),
			'siteurl'        => get_option( 'siteurl' ),
			'home'           => get_option( 'home' ),
			'abspath'        => ABSPATH,
			'table_prefix'   => $GLOBALS['table_prefix'] ?? 'wp_',
			'db_name'        => DB_NAME,
			'php_version'    => PHP_VERSION,
		);
		$zip->addFromString( self::MANIFEST_NAME, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$zip->addFile( $sql_path, 'database.sql' );

		$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
		self::addDirectoryToZip( $zip, $root, 'files', $dir );

		$zip->close();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $sql_path );

		if ( ! is_readable( $zip_path ) ) {
			return array( 'ok' => false, 'error' => 'Becape criado, mas o arquivo não está legível.' );
		}

		return array(
			'ok'    => true,
			'path'  => $zip_path,
			'bytes' => (int) filesize( $zip_path ),
			'name'  => $basename,
		);
	}

	/**
	 * @return list<array{name:string,bytes:int,mtime:int,path:string}>
	 */
	public static function listBecapes(): array {
		$dir = self::storageDir();
		$out = array();
		foreach ( glob( $dir . '/becape-voto-eletronico-*.zip' ) ?: array() as $path ) {
			$out[] = array(
				'name'  => basename( $path ),
				'bytes' => (int) filesize( $path ),
				'mtime' => (int) filemtime( $path ),
				'path'  => $path,
			);
		}
		usort( $out, static fn( $a, $b ) => $b['mtime'] <=> $a['mtime'] );
		return $out;
	}

	/**
	 * Restore from an uploaded or stored becape zip.
	 *
	 * @return array{ok:bool,error?:string,message?:string}
	 */
	public static function restoreFromZip( string $zip_path, string $confirm_phrase ): array {
		if ( 'RESTAURAR' !== trim( $confirm_phrase ) ) {
			return array( 'ok' => false, 'error' => 'Confirmação inválida. Digitar RESTAURAR para prosseguir.' );
		}
		if ( ! class_exists( ZipArchive::class ) || ! is_readable( $zip_path ) ) {
			return array( 'ok' => false, 'error' => 'Arquivo de becape inválido ou ZipArchive indisponível.' );
		}

		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '512M' );

		$tmp = trailingslashit( self::storageDir() ) . 'restore-' . wp_generate_password( 8, false );
		wp_mkdir_p( $tmp );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return array( 'ok' => false, 'error' => 'Não foi possível abrir o becape.' );
		}
		$zip->extractTo( $tmp );
		$zip->close();

		$manifest_path = $tmp . '/' . self::MANIFEST_NAME;
		$sql_path      = $tmp . '/database.sql';
		$files_root    = $tmp . '/files';
		if ( ! is_readable( $manifest_path ) || ! is_readable( $sql_path ) || ! is_dir( $files_root ) ) {
			self::rrmdir( $tmp );
			return array( 'ok' => false, 'error' => 'Becape incompleto (manifesto, base ou arquivos ausentes).' );
		}

		// Guard C3: só restaurar se o manifesto tiver formato e created_utc válidos.
		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
		if ( ! is_array( $manifest ) || ! self::isValidManifest( $manifest ) ) {
			self::rrmdir( $tmp );
			return array( 'ok' => false, 'error' => 'Formato de becape não reconhecido.' );
		}

		$db_ok = self::importDatabase( (string) file_get_contents( $sql_path ) );
		if ( ! $db_ok ) {
			self::rrmdir( $tmp );
			return array( 'ok' => false, 'error' => 'Falha ao restaurar a base de dados.' );
		}

		$copied = self::copyTree( $files_root, untrailingslashit( ABSPATH ) );
		self::rrmdir( $tmp );
		if ( ! $copied ) {
			return array( 'ok' => false, 'error' => 'Base restaurada, mas falhou a cópia dos arquivos.' );
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		return array(
			'ok'      => true,
			'message' => 'Restauração concluída a partir do becape. Recarregue o Painel.',
		);
	}

	private static function exportDatabase(): string {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! is_array( $tables ) || ! $tables ) {
			return '';
		}
		$out  = "-- Voto Eletrônico by RelataSoft — becape de base de dados\n";
		$out .= '-- ' . gmdate( 'c' ) . "\n";
		$out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
		foreach ( $tables as $table ) {
			$table = (string) $table;
			$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . str_replace( '`', '``', $table ) . '`', ARRAY_N );
			if ( ! $create || empty( $create[1] ) ) {
				continue;
			}
			$out .= 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . "`;\n";
			$out .= $create[1] . ";\n\n";
			$rows = $wpdb->get_results( 'SELECT * FROM `' . str_replace( '`', '``', $table ) . '`', ARRAY_A );
			if ( ! is_array( $rows ) || ! $rows ) {
				continue;
			}
			foreach ( $rows as $row ) {
				$vals = array();
				foreach ( $row as $v ) {
					if ( null === $v ) {
						$vals[] = 'NULL';
					} else {
						$vals[] = "'" . esc_sql( (string) $v ) . "'";
					}
				}
				$out .= 'INSERT INTO `' . str_replace( '`', '``', $table ) . '` VALUES (' . implode( ',', $vals ) . ");\n";
			}
			$out .= "\n";
		}
		$out .= "SET FOREIGN_KEY_CHECKS=1;\n";
		return $out;
	}

	private static function importDatabase( string $sql ): bool {
		global $wpdb;
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
		$statements = preg_split( '/;\s*\n/', $sql ) ?: array();
		foreach ( $statements as $statement ) {
			$statement = trim( $statement );
			if ( '' === $statement || str_starts_with( $statement, '--' ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $statement );
		}
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
		return true;
	}

	private static function addDirectoryToZip( ZipArchive $zip, string $abs_root, string $zip_prefix, string $becape_dir ): void {
		$abs_root    = wp_normalize_path( $abs_root );
		$becape_dir  = wp_normalize_path( $becape_dir );
		$iterator    = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $abs_root, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			/** @var \SplFileInfo $file */
			if ( ! $file->isFile() ) {
				continue;
			}
			$path = wp_normalize_path( $file->getPathname() );
			if ( str_starts_with( $path, $becape_dir ) ) {
				continue;
			}
			// Skip heavy/cache paths.
			if ( preg_match( '#/(node_modules|\.git|cache|wflogs)/#', $path ) ) {
				continue;
			}
			$rel = ltrim( substr( $path, strlen( $abs_root ) ), '/' );
			$zip->addFile( $path, $zip_prefix . '/' . $rel );
		}
	}

	private static function copyTree( string $from, string $to ): bool {
		$from = wp_normalize_path( untrailingslashit( $from ) );
		$to   = wp_normalize_path( untrailingslashit( $to ) );
		$it   = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $it as $item ) {
			/** @var \SplFileInfo $item */
			$target = $to . substr( wp_normalize_path( $item->getPathname() ), strlen( $from ) );
			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) ) {
					wp_mkdir_p( $target );
				}
			} else {
				wp_mkdir_p( dirname( $target ) );
				if ( ! copy( $item->getPathname(), $target ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $file ) {
			/** @var \SplFileInfo $file */
			if ( $file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $file->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file->getPathname() );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}
}
