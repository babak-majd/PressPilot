<?php
/**
 * Arbitrary asset upload (fonts, CSS, SVG, images) from base64.
 *
 * The media library sideload only handles images from a URL, so there was no way
 * to host a web font as a real, cacheable file — forcing huge base64 @font-face
 * blobs to be inlined in CSS. This writes an allow-listed file into an uploads
 * subfolder and returns its public URL, so fonts/CSS load once and cache.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Assets {

	const SUBDIR = 'presspilot-assets';

	/** ext => mime allow-list (safe, static asset types only — no PHP/HTML). */
	const ALLOWED = array(
		'woff2' => 'font/woff2',
		'woff'  => 'font/woff',
		'ttf'   => 'font/ttf',
		'otf'   => 'font/otf',
		'css'   => 'text/css',
		'svg'   => 'image/svg+xml',
		'png'   => 'image/png',
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'gif'   => 'image/gif',
		'webp'  => 'image/webp',
		'ico'   => 'image/x-icon',
		'json'  => 'application/json',
	);

	/**
	 * Write a base64 asset into uploads/presspilot-assets and return its URL.
	 *
	 * @param array $args filename, base64, (optional) subdir.
	 * @return array|WP_Error
	 */
	public static function upload( $args ) {
		$filename = isset( $args['filename'] ) ? sanitize_file_name( (string) $args['filename'] ) : '';
		if ( '' === $filename ) {
			return PP_Helpers::error( 'pp_missing_filename', 'A "filename" is required.', 400 );
		}
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! isset( self::ALLOWED[ $ext ] ) ) {
			return PP_Helpers::error( 'pp_bad_ext', sprintf( 'File type ".%s" is not allowed.', $ext ), 415 );
		}
		$b64 = (string) ( isset( $args['base64'] ) ? $args['base64'] : '' );
		// Tolerate a data: URI prefix.
		if ( false !== strpos( $b64, 'base64,' ) ) {
			$b64 = substr( $b64, strpos( $b64, 'base64,' ) + 7 );
		}
		$bytes = base64_decode( $b64, true );
		if ( false === $bytes || '' === $bytes ) {
			return PP_Helpers::error( 'pp_bad_base64', 'The "base64" content is invalid or empty.', 400 );
		}

		$up  = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			return PP_Helpers::error( 'pp_upload_dir', $up['error'], 500 );
		}
		$sub = self::SUBDIR;
		if ( ! empty( $args['subdir'] ) ) {
			$sub .= '/' . trim( sanitize_file_name( (string) $args['subdir'] ), '/' );
		}
		$dir = trailingslashit( $up['basedir'] ) . $sub;
		if ( ! wp_mkdir_p( $dir ) ) {
			return PP_Helpers::error( 'pp_mkdir', 'Could not create the assets directory.', 500 );
		}

		$path = trailingslashit( $dir ) . $filename;
		if ( false === file_put_contents( $path, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return PP_Helpers::error( 'pp_write_failed', 'Could not write the file.', 500 );
		}

		return array(
			'url'   => trailingslashit( $up['baseurl'] ) . $sub . '/' . $filename,
			'bytes' => strlen( $bytes ),
			'mime'  => self::ALLOWED[ $ext ],
			'sha1'  => sha1( $bytes ),
		);
	}

	/**
	 * List files already stored in the assets folder.
	 *
	 * @return array
	 */
	public static function list_all() {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . self::SUBDIR;
		$out = array();
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*' ) as $f ) {
				if ( is_file( $f ) ) {
					$out[] = array(
						'url'   => trailingslashit( $up['baseurl'] ) . self::SUBDIR . '/' . basename( $f ),
						'bytes' => filesize( $f ),
					);
				}
			}
		}
		return $out;
	}
}
