<?php
/**
 * Dangerous capabilities — the handful of tools powerful enough that WordPress is
 * rightly cautious about them: running PHP, installing plugins, raw SQL, and
 * dispatching admin-ajax. Each is OFF by default, shown as a red toggle on the
 * Permissions screen with a loud warning, and — crucially — **auto-disables 30
 * minutes after it is switched on**, so a site is never left permanently open.
 *
 * Storage: one option per capability holding the unix time it was switched on
 * (0/empty = off). `enabled()` returns true only while that stamp is within the
 * TTL; once past it, the option is cleared so the UI and every later check agree.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Danger {

	/** How long a dangerous capability stays on before it auto-disables. */
	const TTL = 1800; // 30 minutes.

	/** Capability key => option name. */
	const CAPS = array(
		'exec'           => 'presspilot_allow_exec',
		'plugin_install' => 'presspilot_allow_plugin_install',
		'raw_sql'        => 'presspilot_allow_raw_sql',
		'admin_ajax'     => 'presspilot_allow_admin_ajax',
	);

	/** The option name for a capability, or '' if unknown. */
	public static function option( $key ) {
		return isset( self::CAPS[ $key ] ) ? self::CAPS[ $key ] : '';
	}

	/**
	 * Is the capability currently on? Only true while within TTL of being switched
	 * on; an expired toggle is cleared here so it reads as off everywhere.
	 *
	 * `exec` additionally honours the PP_ALLOW_EXEC constant (for CI / wp-config
	 * pinning), which has no expiry.
	 */
	public static function enabled( $key ) {
		if ( 'exec' === $key && defined( 'PP_ALLOW_EXEC' ) ) {
			return (bool) PP_ALLOW_EXEC;
		}
		$opt = self::option( $key );
		if ( '' === $opt ) {
			return false;
		}
		$since = (int) get_option( $opt, 0 );
		if ( $since <= 0 ) {
			return false;
		}
		if ( ( time() - $since ) >= self::TTL ) {
			update_option( $opt, 0, false ); // Auto-disable: the half hour is up.
			return false;
		}
		return true;
	}

	/** Seconds left before auto-disable (0 when off). */
	public static function remaining( $key ) {
		if ( ! self::enabled( $key ) ) {
			return 0;
		}
		if ( 'exec' === $key && defined( 'PP_ALLOW_EXEC' ) ) {
			return self::TTL; // Constant override: treat as freshly armed.
		}
		$since = (int) get_option( self::option( $key ), 0 );
		return max( 0, self::TTL - ( time() - $since ) );
	}

	/** Switch a capability on (stamping "now", which re-arms the 30-minute timer) or off. */
	public static function set( $key, $on ) {
		$opt = self::option( $key );
		if ( '' === $opt ) {
			return;
		}
		update_option( $opt, $on ? time() : 0, false );
	}

	/**
	 * Add exactly $seconds to a capability that is currently on, pushing its
	 * auto-off further out (the "Extend 10 minutes" button). Does nothing to a
	 * capability that is off or already expired — re-enable it with the toggle.
	 *
	 * @return bool Whether it was extended.
	 */
	public static function extend( $key, $seconds = 600 ) {
		if ( ! self::enabled( $key ) ) {
			return false;
		}
		$opt   = self::option( $key );
		$since = (int) get_option( $opt, 0 );
		update_option( $opt, $since + (int) $seconds, false );
		return true;
	}

	/**
	 * Display metadata for the Permissions screen — order matters, exec first.
	 *
	 * @return array[] key => { label, tag, desc }
	 */
	public static function labels() {
		return array(
			'exec'           => array(
				'label' => __( 'Allow code execution', 'presspilot' ),
				'tag'   => '/exec',
				'desc'  => __( 'Lets the API run arbitrary PHP on this site — the universal last-resort fallback. As powerful as installing a plugin. Only enable it if you trust the API-key holder completely.', 'presspilot' ),
			),
			'plugin_install' => array(
				'label' => __( 'Allow installing plugins', 'presspilot' ),
				'tag'   => '/plugins/install',
				'desc'  => __( 'Lets the API add new code to the site by installing a plugin from the WordPress.org repository or from an uploaded zip.', 'presspilot' ),
			),
			'raw_sql'        => array(
				'label' => __( 'Allow raw SQL SELECT', 'presspilot' ),
				'tag'   => '/db/select (raw)',
				'desc'  => __( 'Lets the API run a read-only SQL SELECT it was not pre-built to run. The structured database reads keep working without this.', 'presspilot' ),
			),
			'admin_ajax'     => array(
				'label' => __( 'Allow admin-ajax dispatch', 'presspilot' ),
				'tag'   => '/admin-ajax',
				'desc'  => __( 'Lets the API invoke any wp_ajax_{action} handler as an administrator, reaching the private actions of other plugins.', 'presspilot' ),
			),
		);
	}
}
