<?php
/**
 * Read-only filesystem access, scoped to a handful of allow-listed roots.
 *
 * This layer can LIST and READ files, never write them. Every request names one of a
 * fixed set of roots (uploads, content, themes, plugins, wp-admin-free site root) and a
 * relative path; the resolved real path must stay inside that root, so path traversal
 * (`../`) cannot escape it. Reads are capped in size and skip obviously binary files,
 * and a few sensitive files (wp-config.php, .env) are never returned.
 *
 * It exists so an agent can inspect debug logs, theme/plugin source and uploaded files
 * for debugging and security review without the arbitrary-code reach that used to be
 * required for it.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Files {

	/** Hard cap on a single read. */
	const MAX_BYTES = 1048576; // 1 MiB.

	/** Hard cap on directory entries returned. */
	const MAX_ENTRIES = 2000;

	/** Basenames that are never listed or read, whatever the root. */
	const DENY_NAMES = array( 'wp-config.php', '.env', '.env.local', 'wp-config-sample.php' );

	/**
	 * The allow-listed roots, resolved to absolute paths.
	 *
	 * @return array<string,string>
	 */
	private static function roots() {
		$uploads = wp_get_upload_dir();
		$roots   = array(
			'uploads' => isset( $uploads['basedir'] ) ? $uploads['basedir'] : '',
			'content' => defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
			'themes'  => get_theme_root(),
			'plugins' => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			'site'    => untrailingslashit( ABSPATH ),
		);
		$out = array();
		foreach ( $roots as $k => $dir ) {
			if ( $dir ) {
				$real = realpath( $dir );
				if ( $real ) {
					$out[ $k ] = $real;
				}
			}
		}
		return $out;
	}

	/**
	 * Resolve {root, path} to a real absolute path guaranteed to sit inside the root.
	 *
	 * @param string $root Root key.
	 * @param string $path Relative path within the root.
	 * @param bool   $must_exist Whether the target must already exist.
	 * @return string|WP_Error
	 */
	private static function resolve( $root, $path, $must_exist = true ) {
		$roots = self::roots();
		$key   = is_string( $root ) ? $root : '';
		if ( ! isset( $roots[ $key ] ) ) {
			return PP_Helpers::error( 'pp_bad_root', 'root must be one of: ' . implode( ', ', array_keys( $roots ) ) . '.', 400 );
		}
		$base = $roots[ $key ];
		$rel  = ltrim( str_replace( '\\', '/', (string) $path ), '/' );
		$full = $base . ( '' !== $rel ? '/' . $rel : '' );
		$real = realpath( $full );
		if ( false === $real ) {
			if ( $must_exist ) {
				return PP_Helpers::error( 'pp_no_path', 'Path not found.', 404 );
			}
			$real = $full;
		}
		// Containment: the resolved path must be the root itself or sit beneath it.
		if ( $real !== $base && 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
			return PP_Helpers::error( 'pp_path_escape', 'Path escapes its root.', 400 );
		}
		if ( in_array( strtolower( basename( $real ) ), self::DENY_NAMES, true ) ) {
			return PP_Helpers::error( 'pp_path_denied', 'This file is not readable through the API.', 403 );
		}
		return $real;
	}

	/**
	 * List a directory under a root.
	 *
	 * @param array $args { root, path, glob }
	 * @return array|WP_Error
	 */
	public static function list_dir( $args ) {
		$dir = self::resolve( isset( $args['root'] ) ? $args['root'] : '', isset( $args['path'] ) ? $args['path'] : '' );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		if ( ! is_dir( $dir ) ) {
			return PP_Helpers::error( 'pp_not_dir', 'Path is not a directory.', 400 );
		}
		$pattern = isset( $args['glob'] ) && '' !== $args['glob'] ? (string) $args['glob'] : '';
		$names   = scandir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.directory_scandir -- read-only directory listing inside a validated, contained allow-listed root.
		$entries = array();
		foreach ( (array) $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			if ( in_array( strtolower( $name ), self::DENY_NAMES, true ) ) {
				continue;
			}
			if ( '' !== $pattern && ! fnmatch( $pattern, $name ) ) {
				continue;
			}
			$fp      = $dir . '/' . $name;
			$is_dir  = is_dir( $fp );
			$entries[] = array(
				'name'     => $name,
				'type'     => $is_dir ? 'dir' : 'file',
				'size'     => $is_dir ? null : (int) filesize( $fp ),
				'modified' => gmdate( 'c', (int) filemtime( $fp ) ),
			);
			if ( count( $entries ) >= self::MAX_ENTRIES ) {
				break;
			}
		}
		return array( 'root' => $args['root'], 'path' => (string) ( isset( $args['path'] ) ? $args['path'] : '' ), 'entries' => $entries, 'count' => count( $entries ) );
	}

	/**
	 * Read a text file under a root, size-capped.
	 *
	 * @param array $args { root, path }
	 * @return array|WP_Error
	 */
	public static function read( $args ) {
		$file = self::resolve( isset( $args['root'] ) ? $args['root'] : '', isset( $args['path'] ) ? $args['path'] : '' );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! is_file( $file ) ) {
			return PP_Helpers::error( 'pp_not_file', 'Path is not a file.', 400 );
		}
		$size  = (int) filesize( $file );
		$bytes = (string) file_get_contents( $file, false, null, 0, self::MAX_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- read-only read of a validated, contained file under an allow-listed root, size-capped.
		// Refuse obviously binary content (NUL byte in the sampled bytes).
		if ( false !== strpos( substr( $bytes, 0, 8000 ), "\0" ) ) {
			return PP_Helpers::error( 'pp_binary', 'File appears to be binary; not returned.', 415 );
		}
		return array(
			'root'      => $args['root'],
			'path'      => (string) ( isset( $args['path'] ) ? $args['path'] : '' ),
			'size'      => $size,
			'truncated' => $size > self::MAX_BYTES,
			'content'   => $bytes,
		);
	}
}
