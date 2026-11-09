<?php
/**
 * Security & maintenance engine — a read-only scanner plus a vetted, reversible
 * catalogue of hardening and optimization actions.
 *
 * The design keeps everything inside the compliant line:
 *   - Scans are READ-ONLY: they report findings, they never change anything.
 *   - Hardening is mostly a set of OPTION-GATED RUNTIME behaviours that this plugin
 *     applies itself from hooks (disable the file editor, disable XML-RPC, hide the
 *     version, block user enumeration, send security headers). Turning one on or off
 *     is a single option write, so every one of them is instantly reversible and no
 *     file on disk is edited.
 *   - The one action that genuinely needs a file (blocking script execution in the
 *     uploads folder) writes a single known .htaccess block and backs up whatever was
 *     there, so it, too, is reversible.
 *   - Maintenance/cleanup actions use WordPress' own APIs (wp_delete_post_revision,
 *     wp_delete_comment, …), support dry-run, and report exactly what they would touch.
 *
 * There is no arbitrary code, no arbitrary file write, and no arbitrary SQL here: the
 * set of actions is fixed and each one is validated. This is the "limited set of
 * non-modifiable, thoroughly vetted actions" shape.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Security {

	/** Option holding the list of enabled runtime hardening toggles. */
	const OPTION_HARDEN = 'presspilot_harden';

	/** Option holding the backup of the uploads .htaccess we wrote (for undo). */
	const OPTION_UPLOADS_BAK = 'presspilot_harden_uploads_bak';

	/** The known content we write to uploads/.htaccess. */
	const UPLOADS_HTACCESS = "# Added by PressPilot hardening: block script execution in uploads\n<FilesMatch \"\\.(?i:php|php3|php4|php5|php7|phtml|pht|phar|cgi|pl|py|asp|aspx|jsp|sh)$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n</FilesMatch>\n";

	/* ================================================================== */
	/* Runtime enforcement of the enabled toggles (hooked from boot).     */
	/* ================================================================== */

	/** Which runtime toggles are currently enabled. */
	private static function enabled() {
		$v = get_option( self::OPTION_HARDEN, array() );
		return is_array( $v ) ? $v : array();
	}

	private static function is_on( $id ) {
		return in_array( $id, self::enabled(), true );
	}

	/**
	 * Apply the enabled runtime toggles. Called once, early, from the plugin boot.
	 */
	public static function enforce() {
		$on = self::enabled();
		if ( empty( $on ) ) {
			return;
		}
		if ( in_array( 'disable_file_edit', $on, true ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DISALLOW_FILE_EDIT is a WordPress core constant; we set the core value, it is not ours to prefix.
		}
		if ( in_array( 'disable_xmlrpc', $on, true ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'wp_headers', array( __CLASS__, 'strip_pingback_header' ) );
		}
		if ( in_array( 'hide_version', $on, true ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}
		if ( in_array( 'block_user_enum', $on, true ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'block_author_scan' ) );
			add_filter( 'rest_endpoints', array( __CLASS__, 'guard_rest_users' ) );
		}
		if ( in_array( 'security_headers', $on, true ) ) {
			add_filter( 'wp_headers', array( __CLASS__, 'add_security_headers' ) );
		}
	}

	public static function strip_pingback_header( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	public static function add_security_headers( $headers ) {
		if ( ! is_array( $headers ) ) {
			return $headers;
		}
		$headers['X-Content-Type-Options'] = 'nosniff';
		$headers['X-Frame-Options']        = 'SAMEORIGIN';
		$headers['Referrer-Policy']        = 'strict-origin-when-cross-origin';
		return $headers;
	}

	/** Redirect ?author=N probing by logged-out visitors to the home page. */
	public static function block_author_scan() {
		if ( is_user_logged_in() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only inspection of a public query var to decide a redirect; no state changes.
		if ( isset( $_GET['author'] ) && '' !== $_GET['author'] ) {
			wp_safe_redirect( home_url( '/' ), 302 );
			exit;
		}
	}

	/** Remove the REST users endpoints for requests that cannot already list users. */
	public static function guard_rest_users( $endpoints ) {
		if ( current_user_can( 'list_users' ) ) {
			return $endpoints;
		}
		foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ) as $route ) {
			if ( isset( $endpoints[ $route ] ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/* ================================================================== */
	/* The action catalogue.                                              */
	/* ================================================================== */

	/**
	 * Every vetted action: runtime toggles, the one file action, and the one-shot
	 * maintenance cleaners.
	 *
	 * @return array
	 */
	public static function actions() {
		$toggles = array(
			'disable_file_edit' => __( 'Disable the theme/plugin file editor (DISALLOW_FILE_EDIT).', 'presspilot' ),
			'disable_xmlrpc'    => __( 'Disable XML-RPC and remove the pingback header.', 'presspilot' ),
			'hide_version'      => __( 'Hide the WordPress version from the page head and feeds.', 'presspilot' ),
			'block_user_enum'   => __( 'Block user enumeration (?author scans and the REST users list).', 'presspilot' ),
			'security_headers'  => __( 'Send baseline security headers (nosniff, SAMEORIGIN, Referrer-Policy).', 'presspilot' ),
		);
		$catalogue = array();
		foreach ( $toggles as $id => $desc ) {
			$catalogue[ $id ] = array(
				'kind'       => 'toggle',
				'reversible' => true,
				'enabled'    => self::is_on( $id ),
				'description'=> $desc,
			);
		}
		$catalogue['uploads_no_exec'] = array(
			'kind'       => 'file',
			'reversible' => true,
			'enabled'    => (bool) get_option( self::OPTION_UPLOADS_BAK, false ) !== false && null !== get_option( self::OPTION_UPLOADS_BAK, null ),
			'description'=> __( 'Write uploads/.htaccess to block script execution in the uploads folder (backed up; reversible).', 'presspilot' ),
		);
		$maint = array(
			'clean_revisions'         => __( 'Delete all post revisions.', 'presspilot' ),
			'clean_trashed_posts'     => __( 'Permanently delete posts in the Trash.', 'presspilot' ),
			'clean_spam_comments'     => __( 'Permanently delete spam comments.', 'presspilot' ),
			'clean_expired_transients'=> __( 'Delete expired transients from the options table.', 'presspilot' ),
		);
		foreach ( $maint as $id => $desc ) {
			$catalogue[ $id ] = array(
				'kind'       => 'maintenance',
				'reversible' => false,
				'description'=> $desc,
			);
		}
		return array( 'actions' => $catalogue );
	}

	/* ================================================================== */
	/* Apply / undo.                                                      */
	/* ================================================================== */

	const TOGGLES = array( 'disable_file_edit', 'disable_xmlrpc', 'hide_version', 'block_user_enum', 'security_headers' );
	const MAINT   = array( 'clean_revisions', 'clean_trashed_posts', 'clean_spam_comments', 'clean_expired_transients' );

	/**
	 * Apply (or undo) a vetted action.
	 *
	 * @param array $args { action, dry_run, undo }
	 * @return array|WP_Error
	 */
	public static function apply( $args ) {
		$action = isset( $args['action'] ) ? sanitize_key( $args['action'] ) : '';
		$dry    = ! empty( $args['dry_run'] );
		$undo   = ! empty( $args['undo'] );

		if ( in_array( $action, self::TOGGLES, true ) ) {
			return self::set_toggle( $action, ! $undo, $dry );
		}
		if ( 'uploads_no_exec' === $action ) {
			return $undo ? self::undo_uploads_htaccess( $dry ) : self::apply_uploads_htaccess( $dry );
		}
		if ( in_array( $action, self::MAINT, true ) ) {
			if ( $undo ) {
				return PP_Helpers::error( 'pp_not_reversible', 'This maintenance action deletes data and cannot be undone. Run it with dry_run first to preview.', 400 );
			}
			return self::run_maintenance( $action, $dry );
		}
		return PP_Helpers::error( 'pp_bad_action', 'Unknown action. GET /security/actions for the catalogue.', 400 );
	}

	private static function set_toggle( $id, $on, $dry ) {
		$cur = self::enabled();
		$now = in_array( $id, $cur, true );
		if ( $dry ) {
			return array( 'dry_run' => true, 'action' => $id, 'was' => $now, 'would_be' => (bool) $on );
		}
		if ( $on && ! $now ) {
			$cur[] = $id;
		} elseif ( ! $on && $now ) {
			$cur = array_values( array_diff( $cur, array( $id ) ) );
		}
		update_option( self::OPTION_HARDEN, array_values( array_unique( $cur ) ), true );
		return array( 'action' => $id, 'enabled' => (bool) $on, 'note' => 'Applied immediately; reverse by calling apply with undo:true.' );
	}

	private static function uploads_htaccess_path() {
		$u = wp_get_upload_dir();
		return isset( $u['basedir'] ) ? $u['basedir'] . '/.htaccess' : '';
	}

	private static function apply_uploads_htaccess( $dry ) {
		$path = self::uploads_htaccess_path();
		if ( '' === $path ) {
			return PP_Helpers::error( 'pp_no_uploads', 'Could not resolve the uploads directory.', 500 );
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		$existed = $wp_filesystem->exists( $path );
		$prev    = $existed ? (string) $wp_filesystem->get_contents( $path ) : '';
		if ( $dry ) {
			return array( 'dry_run' => true, 'action' => 'uploads_no_exec', 'path' => $path, 'existed_before' => $existed );
		}
		// Keep a backup for undo (absent marker when there was no file).
		update_option( self::OPTION_UPLOADS_BAK, $existed ? $prev : array( '__pp_absent' => true ), false );
		$ok = $wp_filesystem->put_contents( $path, self::UPLOADS_HTACCESS, FS_CHMOD_FILE );
		if ( ! $ok ) {
			return PP_Helpers::error( 'pp_write_failed', 'Could not write uploads/.htaccess (filesystem not writable by WordPress).', 500 );
		}
		return array( 'action' => 'uploads_no_exec', 'path' => $path, 'written' => true, 'had_previous' => $existed );
	}

	private static function undo_uploads_htaccess( $dry ) {
		$path = self::uploads_htaccess_path();
		$bak  = get_option( self::OPTION_UPLOADS_BAK, null );
		if ( null === $bak ) {
			return PP_Helpers::error( 'pp_no_backup', 'No PressPilot backup of uploads/.htaccess to restore.', 404 );
		}
		if ( $dry ) {
			return array( 'dry_run' => true, 'action' => 'uploads_no_exec', 'undo' => true, 'path' => $path );
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		if ( is_array( $bak ) && ! empty( $bak['__pp_absent'] ) ) {
			if ( $wp_filesystem->exists( $path ) ) {
				$wp_filesystem->delete( $path );
			}
		} else {
			$wp_filesystem->put_contents( $path, (string) $bak, FS_CHMOD_FILE );
		}
		delete_option( self::OPTION_UPLOADS_BAK );
		return array( 'action' => 'uploads_no_exec', 'undo' => true, 'restored' => true );
	}

	private static function run_maintenance( $action, $dry ) {
		switch ( $action ) {
			case 'clean_revisions':
				$ids = get_posts( array( 'post_type' => 'revision', 'post_status' => 'any', 'numberposts' => 1000, 'fields' => 'ids' ) );
				if ( $dry ) {
					return array( 'dry_run' => true, 'action' => $action, 'would_delete' => count( $ids ) );
				}
				$n = 0;
				foreach ( $ids as $id ) {
					if ( wp_delete_post_revision( $id ) ) {
						++$n;
					}
				}
				return array( 'action' => $action, 'deleted' => $n );

			case 'clean_trashed_posts':
				$ids = get_posts( array( 'post_type' => 'any', 'post_status' => 'trash', 'numberposts' => 1000, 'fields' => 'ids' ) );
				if ( $dry ) {
					return array( 'dry_run' => true, 'action' => $action, 'would_delete' => count( $ids ) );
				}
				$n = 0;
				foreach ( $ids as $id ) {
					if ( wp_delete_post( $id, true ) ) {
						++$n;
					}
				}
				return array( 'action' => $action, 'deleted' => $n );

			case 'clean_spam_comments':
				$ids = get_comments( array( 'status' => 'spam', 'number' => 2000, 'fields' => 'ids' ) );
				if ( $dry ) {
					return array( 'dry_run' => true, 'action' => $action, 'would_delete' => count( $ids ) );
				}
				$n = 0;
				foreach ( $ids as $id ) {
					if ( wp_delete_comment( $id, true ) ) {
						++$n;
					}
				}
				return array( 'action' => $action, 'deleted' => $n );

			case 'clean_expired_transients':
				global $wpdb;
				$now  = time();
				$like = $wpdb->esc_like( '_transient_timeout_' ) . '%';
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 5000", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scanning transient-timeout rows by prefix; prepared, and this cleanup is not cached.
				$expired = array();
				foreach ( $rows as $r ) {
					if ( (int) $r['option_value'] < $now ) {
						$expired[] = substr( $r['option_name'], strlen( '_transient_timeout_' ) );
					}
				}
				if ( $dry ) {
					return array( 'dry_run' => true, 'action' => $action, 'would_delete' => count( $expired ) );
				}
				$n = 0;
				foreach ( $expired as $name ) {
					if ( delete_transient( $name ) ) {
						++$n;
					}
				}
				return array( 'action' => $action, 'deleted' => $n );
		}
		return PP_Helpers::error( 'pp_bad_action', 'Unknown maintenance action.', 400 );
	}

	/* ================================================================== */
	/* Read-only scanners.                                                */
	/* ================================================================== */

	const CHECKS = array( 'files', 'config', 'accounts', 'plugins' );

	/**
	 * Run read-only scans.
	 *
	 * @param array $args { checks:[] }
	 * @return array
	 */
	public static function scan( $args ) {
		$want = isset( $args['checks'] ) && is_array( $args['checks'] ) ? array_intersect( $args['checks'], self::CHECKS ) : self::CHECKS;
		$out  = array();
		foreach ( $want as $c ) {
			$out[ $c ] = call_user_func( array( __CLASS__, 'scan_' . $c ) );
		}
		return array( 'scans' => $out );
	}

	private static function scan_files() {
		$u    = wp_get_upload_dir();
		$base = isset( $u['basedir'] ) ? $u['basedir'] : '';
		$php  = array();
		$susp = array();
		if ( $base && is_dir( $base ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
			$seen = 0;
			foreach ( $it as $file ) {
				if ( ++$seen > 20000 ) {
					break;
				}
				$name = $file->getFilename();
				if ( preg_match( '/\.(php|phtml|phar|pht)$/i', $name ) ) {
					$php[] = str_replace( $base, '', $file->getPathname() );
				}
			}
		}
		return array(
			'uploads_executable_files' => array_slice( $php, 0, 200 ),
			'uploads_executable_count' => count( $php ),
			'note'                     => count( $php ) ? 'PHP files in uploads are a common backdoor vector. Review them and enable the uploads_no_exec action.' : 'No executable files found in uploads.',
		);
	}

	private static function scan_config() {
		$htaccess = self::uploads_htaccess_path();
		return array(
			'disallow_file_edit'    => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'debug_on'              => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'debug_display_on'      => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'xmlrpc_hardened'       => self::is_on( 'disable_xmlrpc' ),
			'version_hidden'        => self::is_on( 'hide_version' ),
			'user_enum_blocked'     => self::is_on( 'block_user_enum' ),
			'security_headers_on'   => self::is_on( 'security_headers' ),
			'uploads_no_exec'       => $htaccess && file_exists( $htaccess ),
			'ssl_admin'             => (bool) ( defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN ),
		);
	}

	private static function scan_accounts() {
		$admins = get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'user_login', 'user_email' ) ) );
		$list   = array();
		$weak   = array();
		foreach ( $admins as $u ) {
			$list[] = array( 'id' => (int) $u->ID, 'login' => $u->user_login );
			if ( in_array( strtolower( $u->user_login ), array( 'admin', 'administrator', 'root', 'test' ), true ) ) {
				$weak[] = $u->user_login;
			}
		}
		return array(
			'administrators'       => $list,
			'administrator_count'  => count( $list ),
			'guessable_usernames'  => $weak,
		);
	}

	private static function scan_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$updates  = get_site_transient( 'update_plugins' );
		$inactive = array();
		$outdated = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( ! is_plugin_active( $file ) ) {
				$inactive[] = $data['Name'];
			}
			if ( isset( $updates->response[ $file ] ) ) {
				$outdated[] = $data['Name'];
			}
		}
		return array(
			'inactive_plugins'      => $inactive,
			'plugins_with_updates'  => $outdated,
			'note'                  => 'Inactive plugins still ship code on disk; delete the ones you do not need. Apply pending updates.',
		);
	}
}
