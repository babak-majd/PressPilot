<?php
/**
 * The built-in copilot: a tool-calling agent that runs inside WordPress.
 *
 * PP_Providers supplies the wire, PP_Tools supplies the hands, and this class is
 * the loop between them — plus the system prompt, which is the Skill itself. An
 * agent driven from here is held to exactly the same operating rules as one that
 * connected over MCP; there is one manual, not two.
 *
 * Two entry points, because the two callers have different failure modes:
 *   - step()  one model round trip. The admin chat drives the loop from the
 *             browser, so no single HTTP request can hit max_execution_time and
 *             the user watches each tool call as it happens.
 *   - run()   the whole loop server-side, for headless/API callers.
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Agent {

	/** Hard ceiling on loop iterations regardless of what a caller asks for. */
	const MAX_STEPS = 40;

	/**
	 * The system prompt: the Skill, plus who the model is and where it is standing.
	 *
	 * @return string
	 */
	public static function system_prompt() {
		$path  = PP_PATH . 'docs/SKILL.md';
		$skill = is_readable( $path ) ? file_get_contents( $path ) : '';
		$theme = wp_get_theme();

		$disabled = array_keys( array_filter( PP_Auth::get_scopes(), function ( $on ) { return ! $on; } ) );

		$prompt  = "You are " . PP_PRODUCT . ", an autonomous web developer working directly inside the WordPress site \"" . get_bloginfo( 'name' ) . "\" (" . home_url() . ").\n";
		$prompt .= "You act through the tools you have been given — they are this site's own API. There is no filesystem and no shell; every change is a tool call.\n\n";

		$prompt .= "HOW TO WORK\n";
		$prompt .= "- The operating manual below is authoritative. Follow it exactly; it encodes the rules that make builds on this platform actually work.\n";
		$prompt .= "- Call wp_get_site before choosing an approach, and prefer native Gutenberg blocks unless the site must keep Elementor.\n";
		$prompt .= "- Preview configuration writes with dry_run:true and show the user the diff before committing. Writes create a restore point; report the restore_id.\n";
		$prompt .= "- Batch related work with wp_batch instead of one call per page.\n";
		$prompt .= "- Ask before anything destructive or irreversible: deleting content, switching themes, deactivating plugins, writing to the database.\n";
		$prompt .= "- When you are done, say plainly what you changed and give the live URLs. If a tool failed, say so and what you did about it — never claim work you did not verify.\n";
		$prompt .= "- Answer in the language the user writes to you in.\n\n";

		$prompt .= "ENVIRONMENT\n";
		$prompt .= '- WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . "\n";
		$prompt .= '- Theme: "' . $theme->get( 'Name' ) . '"' . ( PP_FSE::is_block_theme() ? ' (block/FSE theme — use FSE templates and global styles)' : ' (classic theme — use Additional CSS for global styles)' ) . "\n";
		$prompt .= '- Locale: ' . get_bloginfo( 'language' ) . ( is_rtl() ? ' (RTL)' : '' ) . ', timezone ' . wp_timezone_string() . "\n";
		$prompt .= '- Elementor: ' . ( PP_Helpers::elementor_active() ? 'active' : 'not active' ) . "\n";
		if ( $disabled ) {
			$prompt .= '- Capabilities the administrator turned OFF: ' . implode( ', ', $disabled ) . ". Their tools are hidden from you. Do not try to work around them — tell the user to enable them on the Permissions screen.\n";
		}

		$prompt .= "\n=== OPERATING MANUAL (the PressPilot Skill) ===\n\n" . $skill;

		return $prompt;
	}

	/* ------------------------------------------------------------------ */
	/* One round trip                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Send the conversation to the model once, run whatever tools it asked for,
	 * and hand back the extended conversation.
	 *
	 * @param array $messages Provider-native messages so far.
	 * @param array $config   Provider config (defaults to the saved one).
	 * @return array{messages:array,text:string,tool_calls:array,done:bool,usage:array}|WP_Error
	 */
	public static function step( $messages, $config = null ) {
		$config = $config ? $config : PP_Providers::config();

		$tools = 'anthropic' === $config['api']
			? PP_Tools::anthropic_definitions()
			: PP_Tools::openai_definitions();

		$reply = PP_Providers::chat( $messages, $tools, self::system_prompt(), $config );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$messages[] = $reply['assistant'];

		// No tool calls means the model is talking to the user: the turn is over.
		if ( empty( $reply['tool_calls'] ) ) {
			return array(
				'messages'   => $messages,
				'text'       => $reply['text'],
				'tool_calls' => array(),
				'done'       => true,
				'usage'      => $reply['usage'],
			);
		}

		$results  = array();
		$reported = array();
		foreach ( $reply['tool_calls'] as $call ) {
			$result = PP_Tools::call( $call['name'], $call['args'] );
			$text   = PP_Tools::render_result( $result );

			$results[] = array(
				'id'       => $call['id'],
				'text'     => $text,
				'is_error' => ! $result['ok'],
			);
			$reported[] = array(
				'name'    => $call['name'],
				'args'    => $call['args'],
				'ok'      => $result['ok'],
				'status'  => $result['status'],
				'summary' => self::summarise( $call['name'], $result ),
			);
		}

		foreach ( PP_Providers::tool_result_messages( $results, $config ) as $message ) {
			$messages[] = $message;
		}

		return array(
			'messages'   => $messages,
			'text'       => $reply['text'],
			'tool_calls' => $reported,
			'done'       => false,
			'usage'      => $reply['usage'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* The whole loop                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Run to completion server-side. For headless callers — a cron job, a script,
	 * another agent delegating a task to the site.
	 *
	 * @param string $prompt    What to do.
	 * @param array  $messages  Prior conversation to continue (optional).
	 * @param int    $max_steps Iteration cap.
	 * @return array|WP_Error
	 */
	public static function run( $prompt, $messages = array(), $max_steps = 0 ) {
		$config    = PP_Providers::config();
		$max_steps = $max_steps > 0 ? min( self::MAX_STEPS, (int) $max_steps ) : $config['max_steps'];

		$messages   = is_array( $messages ) ? $messages : array();
		if ( '' !== (string) $prompt ) {
			$messages[] = PP_Providers::user_message( $prompt, $config );
		}

		// Tool loops are long; give the request as much wall clock as the host allows.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$steps = array();
		$text  = '';
		$done  = false;

		for ( $i = 0; $i < $max_steps; $i++ ) {
			$step = self::step( $messages, $config );
			if ( is_wp_error( $step ) ) {
				// Keep what already happened — a provider error on step 6 should not
				// throw away five steps of real changes.
				return array(
					'ok'       => false,
					'error'    => array( 'code' => $step->get_error_code(), 'message' => $step->get_error_message() ),
					'steps'    => $steps,
					'messages' => $messages,
					'text'     => $text,
				);
			}

			$messages = $step['messages'];
			$text     = '' !== $step['text'] ? $step['text'] : $text;
			$steps[]  = array( 'text' => $step['text'], 'tool_calls' => $step['tool_calls'] );

			if ( $step['done'] ) {
				$done = true;
				break;
			}
		}

		return array(
			'ok'        => true,
			'done'      => $done,
			'text'      => $text,
			'steps'     => $steps,
			'messages'  => $messages,
			'truncated' => ! $done,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * A one-line description of what a tool call did, for the transcript UI —
	 * the full JSON goes to the model, the human gets the headline.
	 *
	 * @param string $name   Tool name.
	 * @param array  $result Result from PP_Tools::call().
	 * @return string
	 */
	private static function summarise( $name, $result ) {
		$data = $result['data'];

		if ( ! $result['ok'] ) {
			if ( is_array( $data ) && isset( $data['message'] ) ) {
				return (string) $data['message'];
			}
			return 'failed with HTTP ' . $result['status'];
		}
		if ( is_array( $data ) ) {
			if ( isset( $data['url'] ) ) {
				return (string) $data['url'];
			}
			if ( isset( $data['id'] ) ) {
				return '#' . $data['id'] . ( isset( $data['title'] ) ? ' — ' . $data['title'] : '' );
			}
			if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
				return count( $data['items'] ) . ' item(s)';
			}
			if ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
				return count( $data['results'] ) . ' sub-request(s)';
			}
			return 'ok';
		}
		return 'ok';
	}
}
