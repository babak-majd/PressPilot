<?php
/**
 * Native contact-form handler.
 *
 * A dependency-free way to make a plain HTML <form> actually send email, so a
 * migrated site doesn't need a form plugin or a mailto: fallback. The submit
 * endpoint is public (forms are filled by anonymous visitors) but protected by a
 * honeypot field, a minimum fill-time, and a per-IP rate limit.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Forms {

	const OPTION = 'presspilot_forms';

	/** Config with sane defaults (recipient falls back to the admin email). */
	public static function config() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array_merge(
			array(
				'recipient'    => get_option( 'admin_email' ),
				'subject'      => sprintf( '[%s] New contact message', get_bloginfo( 'name' ) ),
				'success'      => 'Thanks — your message was sent. We\'ll get back to you shortly.',
				'honeypot'     => 'pp_hp',   // a field that must stay empty.
				'min_seconds'  => 2,          // faster than this = a bot.
				'rate_per_hour'=> 10,         // per IP.
			),
			$saved
		);
	}

	/**
	 * Persist form config (recipient, subject, success message, anti-spam knobs).
	 *
	 * @param array $args Partial config.
	 * @return array
	 */
	public static function set_config( $args ) {
		$cur = self::config();
		if ( isset( $args['recipient'] ) ) {
			$cur['recipient'] = sanitize_email( (string) $args['recipient'] );
		}
		if ( isset( $args['subject'] ) ) {
			$cur['subject'] = sanitize_text_field( (string) $args['subject'] );
		}
		if ( isset( $args['success'] ) ) {
			$cur['success'] = sanitize_text_field( (string) $args['success'] );
		}
		if ( isset( $args['min_seconds'] ) ) {
			$cur['min_seconds'] = max( 0, (int) $args['min_seconds'] );
		}
		if ( isset( $args['rate_per_hour'] ) ) {
			$cur['rate_per_hour'] = max( 1, (int) $args['rate_per_hour'] );
		}
		update_option( self::OPTION, $cur, false );
		return $cur;
	}

	/**
	 * Handle a public form submission → validate, anti-spam, then wp_mail.
	 *
	 * @param array  $fields Submitted field map (label => value).
	 * @param string $ip     Client IP.
	 * @param array  $meta   { _t: render timestamp, <honeypot>: should be empty }.
	 * @return array|WP_Error
	 */
	public static function submit( $fields, $ip, $meta ) {
		$cfg = self::config();

		// 1) Honeypot: a hidden field bots tend to fill.
		if ( ! empty( $meta[ $cfg['honeypot'] ] ) ) {
			return array( 'sent' => true ); // pretend success; drop silently.
		}
		// 2) Minimum fill time.
		$rendered = isset( $meta['_t'] ) ? (int) $meta['_t'] : 0;
		if ( $rendered && ( time() - $rendered ) < (int) $cfg['min_seconds'] ) {
			return PP_Helpers::error( 'pp_too_fast', 'Submission rejected. Please try again.', 429 );
		}
		// 3) Per-IP rate limit.
		$key   = 'pp_form_' . md5( (string) $ip );
		$count = (int) get_transient( $key );
		if ( $count >= (int) $cfg['rate_per_hour'] ) {
			return PP_Helpers::error( 'pp_rate_limited', 'Too many submissions. Please try again later.', 429 );
		}

		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return PP_Helpers::error( 'pp_empty_form', 'No form fields were submitted.', 400 );
		}

		// Build the email body from the submitted fields.
		$lines     = array();
		$reply_to  = '';
		foreach ( $fields as $label => $value ) {
			$label = sanitize_text_field( (string) $label );
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				continue;
			}
			if ( '' === $reply_to && is_email( $value ) ) {
				$reply_to = sanitize_email( $value );
			}
			$lines[] = $label . ': ' . wp_strip_all_tags( $value );
		}
		if ( empty( $lines ) ) {
			return PP_Helpers::error( 'pp_empty_form', 'The form was empty.', 400 );
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		$body = implode( "\n", $lines ) . "\n\n—\nSent from " . home_url( '/' );

		$ok = wp_mail( $cfg['recipient'], $cfg['subject'], $body, $headers );
		if ( ! $ok ) {
			return PP_Helpers::error( 'pp_mail_failed', 'The message could not be sent. Please email us directly.', 502 );
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return array( 'sent' => true, 'message' => $cfg['success'] );
	}
}
