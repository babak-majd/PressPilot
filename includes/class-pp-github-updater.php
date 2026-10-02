<?php
/**
 * Update PressPilot from its GitHub releases, the same way WordPress updates any
 * other plugin: the update shows up on Dashboard > Updates and on the Plugins
 * screen, "View version details" opens the release notes, and per-plugin
 * auto-updates can be switched on from the Plugins screen.
 *
 * This hangs off the `Update URI` plugin header (WordPress 5.8+). Because that
 * header points at github.com rather than wordpress.org, WordPress stops asking
 * the .org directory about this plugin -- so an unrelated plugin that happens to
 * share the "presspilot" slug there can never be pushed over this install -- and
 * instead fires `update_plugins_github.com`, which is where we answer.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_GitHub_Updater {

	/** Transient holding the parsed latest release (or a failure marker). */
	const CACHE_KEY = 'pp_github_release';

	/** How long a good answer is reused before we ask GitHub again. */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** How long a failure is remembered, so an offline or rate-limited site backs off. */
	const FAIL_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Register the hooks. Called once from the plugin bootstrap.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
		add_filter( 'plugin_action_links_' . plugin_basename( PP_FILE ), array( __CLASS__, 'action_link' ) );
		add_action( 'admin_post_pp_check_update', array( __CLASS__, 'handle_manual_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'manual_check_notice' ) );
	}

	/**
	 * `owner/repo`, read from the Update URI header so the header stays the one
	 * source of truth for where this plugin updates from.
	 *
	 * @return string Empty string when the header is missing or not a GitHub repo.
	 */
	private static function repo() {
		$data = get_file_data( PP_FILE, array( 'uri' => 'Update URI' ) );
		$uri  = isset( $data['uri'] ) ? $data['uri'] : '';
		if ( ! preg_match( '~^https?://(?:www\.)?github\.com/([^/]+/[^/?#]+)~i', $uri, $m ) ) {
			return '';
		}
		return rtrim( $m[1], '/' );
	}

	/**
	 * Our own `plugin_basename()`, e.g. "presspilot/presspilot.php".
	 *
	 * @return string
	 */
	private static function basename() {
		return plugin_basename( PP_FILE );
	}

	/**
	 * Answer WordPress's update check for this plugin.
	 *
	 * The filter fires for EVERY installed plugin whose Update URI is on
	 * github.com, so the first thing to do is make sure the plugin being asked
	 * about is actually us.
	 *
	 * @param array|false $update      Update data, false when there is none.
	 * @param array       $plugin_data Plugin headers of the plugin being checked.
	 * @param string      $plugin_file Plugin file, relative to the plugins dir.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}

		$release = self::latest_release();
		if ( ! $release || version_compare( PP_VERSION, $release['version'], '>=' ) ) {
			return $update;
		}

		return array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => dirname( self::basename() ),
			'plugin'       => self::basename(),
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'tested'       => $release['tested'],
		);
	}

	/**
	 * Fill the "View version details" modal, which WordPress asks for by slug.
	 *
	 * @param false|object|array $result The result object or array. Default false.
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( self::basename() ) !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}

		$headers = get_file_data(
			PP_FILE,
			array(
				'name'   => 'Plugin Name',
				'author' => 'Author',
				'uri'    => 'Plugin URI',
				'desc'   => 'Description',
			)
		);

		return (object) array(
			'name'          => $headers['name'],
			'slug'          => $args->slug,
			'version'       => $release['version'],
			'author'        => $headers['author'],
			'homepage'      => $headers['uri'],
			'download_link' => $release['package'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'tested'        => $release['tested'],
			'last_updated'  => $release['date'],
			'sections'      => array(
				'description' => wpautop( esc_html( $headers['desc'] ) ),
				'changelog'   => $release['changelog'],
			),
		);
	}

	/**
	 * Make sure the unpacked folder is named after the plugin.
	 *
	 * A zip built by build.sh already unpacks to `presspilot/`, but a release cut
	 * without that asset falls back to GitHub's generated zipball, which unpacks
	 * to `owner-repo-<sha>/`. Left alone, WordPress would install that as a
	 * second, separate plugin.
	 *
	 * @param string       $source        Path to the unpacked package.
	 * @param string       $remote_source Path to the upgrade working directory.
	 * @param WP_Upgrader  $upgrader      Upgrader instance.
	 * @param array        $hook_extra    Extra arguments describing the upgrade.
	 * @return string|WP_Error
	 */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}

		$slug = dirname( self::basename() );
		if ( basename( $source ) === $slug ) {
			return $source;
		}

		global $wp_filesystem;
		$corrected = trailingslashit( $remote_source ) . $slug;
		if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $corrected, true ) ) {
			return new WP_Error(
				'pp_rename_failed',
				__( 'Could not rename the downloaded PressPilot folder before installing it.', 'presspilot' )
			);
		}
		return trailingslashit( $corrected );
	}

	/**
	 * Add a "Check for updates" link next to Activate/Deactivate.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function action_link( $links ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=pp_check_update' ), 'pp_check_update' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'presspilot' ) . '</a>';
		return $links;
	}

	/**
	 * Drop the cache, re-run WordPress's plugin update check, and report back.
	 */
	public static function handle_manual_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to update plugins.', 'presspilot' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pp_check_update' );

		delete_site_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$release = self::latest_release();
		if ( ! $release ) {
			$status = 'error';
		} elseif ( version_compare( PP_VERSION, $release['version'], '<' ) ) {
			$status = 'available';
		} else {
			$status = 'current';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'pp_update_check' => $status,
					'pp_latest'       => $release ? rawurlencode( $release['version'] ) : '',
				),
				self_admin_url( 'plugins.php' )
			)
		);
		exit;
	}

	/**
	 * The latest published release, cached.
	 *
	 * @return array|false Release data, or false when it could not be fetched.
	 */
	private static function latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'failed' === $cached ) {
			return false;
		}

		$repo = self::repo();
		if ( '' === $repo ) {
			set_site_transient( self::CACHE_KEY, 'failed', self::FAIL_TTL );
			return false;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::CACHE_KEY, 'failed', self::FAIL_TTL );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_site_transient( self::CACHE_KEY, 'failed', self::FAIL_TTL );
			return false;
		}

		$release = self::parse_release( $body );
		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
		return $release;
	}

	/**
	 * Turn GitHub's release JSON into the handful of fields WordPress wants.
	 *
	 * The download is the `presspilot.zip` asset when the release has one --
	 * that is the build this repo ships, already laid out with the plugin folder
	 * at its root. Otherwise fall back to the generated source zipball, which
	 * fix_source_dir() reshapes on the way in.
	 *
	 * @param array $body Decoded release payload.
	 * @return array
	 */
	private static function parse_release( $body ) {
		$package = isset( $body['zipball_url'] ) ? $body['zipball_url'] : '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
					$package = $asset['browser_download_url'];
					break;
				}
			}
		}

		// Read the support headers from the running copy: they describe what this
		// plugin needs, and a release that changed them ships its own readme anyway.
		$headers = get_file_data(
			PP_FILE,
			array(
				'requires'     => 'Requires at least',
				'requires_php' => 'Requires PHP',
			)
		);

		return array(
			'version'      => ltrim( isset( $body['tag_name'] ) ? $body['tag_name'] : '', 'vV' ),
			'package'      => $package,
			'url'          => isset( $body['html_url'] ) ? $body['html_url'] : '',
			'date'         => isset( $body['published_at'] ) ? $body['published_at'] : '',
			'requires'     => $headers['requires'],
			'requires_php' => $headers['requires_php'],
			'tested'       => get_bloginfo( 'version' ),
			'changelog'    => self::changelog_html( isset( $body['body'] ) ? $body['body'] : '' ),
		);
	}

	/**
	 * Render the release notes for the details modal.
	 *
	 * Release bodies are Markdown and the modal wants HTML. Everything is escaped
	 * first, then the few constructs release notes actually use are converted --
	 * anything else is left as the plain text it was written as.
	 *
	 * @param string $markdown Raw release body.
	 * @return string
	 */
	private static function changelog_html( $markdown ) {
		$markdown = trim( (string) $markdown );
		if ( '' === $markdown ) {
			return '<p>' . esc_html__( 'No release notes were published for this version.', 'presspilot' ) . '</p>';
		}

		$html  = '';
		$list  = false;
		$lines = preg_split( '/\R/', esc_html( $markdown ) );

		foreach ( $lines as $line ) {
			$line = rtrim( $line );

			if ( preg_match( '/^\s*[-*]\s+(.*)$/', $line, $m ) ) {
				if ( ! $list ) {
					$html .= '<ul>';
					$list  = true;
				}
				$html .= '<li>' . self::inline_markdown( $m[1] ) . '</li>';
				continue;
			}

			if ( $list ) {
				$html .= '</ul>';
				$list  = false;
			}

			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				$level = min( 6, max( 3, strlen( $m[1] ) ) ); // Keep modal headings below the section title.
				$html .= '<h' . $level . '>' . self::inline_markdown( $m[2] ) . '</h' . $level . '>';
			} elseif ( '' !== trim( $line ) ) {
				$html .= '<p>' . self::inline_markdown( $line ) . '</p>';
			}
		}

		if ( $list ) {
			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * Bold, italic, inline code and links, on already-escaped text.
	 *
	 * @param string $text Escaped line.
	 * @return string
	 */
	private static function inline_markdown( $text ) {
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text );
		$text = preg_replace(
			'~\[([^\]]+)\]\((https?://[^\s)]+)\)~',
			'<a href="$2" rel="nofollow ugc">$1</a>',
			$text
		);
		return $text;
	}

	/**
	 * Show the result of a manual check on the Plugins screen.
	 */
	public static function manual_check_notice() {
		if ( empty( $_GET['pp_update_check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$status = sanitize_key( wp_unslash( $_GET['pp_update_check'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$latest = isset( $_GET['pp_latest'] ) ? sanitize_text_field( wp_unslash( $_GET['pp_latest'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'available' === $status ) {
			$class   = 'notice-warning';
			/* translators: %s: version number of the available release. */
			$message = sprintf( __( 'PressPilot %s is available on GitHub. Update it from the list below.', 'presspilot' ), $latest );
		} elseif ( 'current' === $status ) {
			$class   = 'notice-success';
			/* translators: %s: version number of the installed plugin. */
			$message = sprintf( __( 'PressPilot %s is the latest release.', 'presspilot' ), PP_VERSION );
		} else {
			$class   = 'notice-error';
			$message = __( 'PressPilot could not reach GitHub to check for updates. Try again in a few minutes.', 'presspilot' );
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}
}
