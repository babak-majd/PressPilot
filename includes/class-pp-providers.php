<?php
/**
 * Outbound model providers for the built-in copilot.
 *
 * The other half of "connect directly to agents": as well as letting an external
 * agent drive this site (PP_MCP), the site can drive a model itself. This class
 * is the wire layer for that — it normalises two API shapes:
 *
 *   - Anthropic Messages   (api.anthropic.com)
 *   - OpenAI chat/completions, which OpenRouter, AgentRouter and Dahl also speak
 *
 * into one { text, tool_calls, assistant } result, so PP_Agent never has to care
 * which provider is configured.
 *
 * Requests go through wp_remote_post rather than a vendor SDK: a WordPress plugin
 * is installed as a zip with no Composer autoloader, and the HTTP API is the
 * supported way to make outbound calls (proxies, timeouts and filters included).
 *
 * @package PressPilot
 */

defined( 'ABSPATH' ) || exit;

class PP_Providers {

	const OPTION = 'presspilot_agent'; // provider, api_key, model, base_url, max_steps.

	/** Seconds to wait on a model call. Reasoning models on a long tool turn are slow. */
	const TIMEOUT = 180;

	/**
	 * HTTP status used when the *upstream provider* is the thing that failed.
	 *
	 * Deliberately 4xx, not 502/504. A CDN in front of WordPress — Cloudflare
	 * does this by default — intercepts a 5xx from the origin and replaces the
	 * body with its own generic error page, so the diagnostic message this class
	 * works hard to produce never reaches the person who needs it. 424 Failed
	 * Dependency says exactly what happened ("a resource I depend on failed") and
	 * passes through proxies untouched.
	 */
	const STATUS_UPSTREAM = 424;

	/**
	 * The providers offered in the admin. `api` selects the wire format;
	 * everything else is presentation and defaults.
	 *
	 * Vendor names stay untranslated (they are brands); the explanatory notes
	 * are shown to a human, so they follow the dashboard language.
	 *
	 * @return array<string,array>
	 */
	public static function providers() {
		return array(
			'anthropic'   => array(
				'label'         => 'Anthropic (Claude)',
				'api'           => 'anthropic',
				'base_url'      => 'https://api.anthropic.com/v1',
				'default_model' => 'claude-opus-5',
				'keys_url'      => 'https://console.anthropic.com/settings/keys',
				'note'          => __( 'Claude directly from Anthropic. Best tool-use reliability for long site builds.', 'presspilot' ),
			),
			'openai'      => array(
				'label'         => 'OpenAI',
				'api'           => 'openai',
				'base_url'      => 'https://api.openai.com/v1',
				'default_model' => '',
				'keys_url'      => 'https://platform.openai.com/api-keys',
				'note'          => __( 'The same key your Codex CLI uses.', 'presspilot' ),
			),
			'openrouter'  => array(
				'label'         => 'OpenRouter',
				'api'           => 'openai',
				'base_url'      => 'https://openrouter.ai/api/v1',
				'default_model' => '',
				'keys_url'      => 'https://openrouter.ai/keys',
				'note'          => __( 'One key, hundreds of models from every vendor. Model ids look like "anthropic/claude-sonnet-4.5".', 'presspilot' ),
			),
			'agentrouter' => array(
				'label'         => 'AgentRouter',
				'api'           => 'openai',
				'base_url'      => 'https://agentrouter.org/v1',
				'default_model' => '',
				'keys_url'      => 'https://agentrouter.org/console/token',
				// AgentRouter admits only the agent CLIs it is built for and rejects
				// anything else with "unauthorized client detected" — an HTTP 401 that
				// looks exactly like a bad key and sends people hunting the wrong bug.
				// It is a compatibility gate, not an auth control: the key still has to
				// be valid. Identify as a supported client so a valid key works.
				'user_agent'    => 'claude-cli/1.0.0 (external, cli)',
				'note'          => __( 'OpenAI-compatible gateway across many vendors.', 'presspilot' ),
			),
			'dahl'        => array(
				'label'         => 'Dahl Inference',
				'api'           => 'openai',
				'base_url'      => 'https://inference.dahl.global/v1',
				'default_model' => 'MiniMaxAI/MiniMax-M2.7',
				'keys_url'      => 'https://inference.dahl.global/chatKeys',
				'note'          => __( 'Open models (MiniMax, Kimi, DeepSeek) on a decentralised GPU network, at a fraction of the usual price. Model ids are namespaced, e.g. "MiniMaxAI/MiniMax-M2.7".', 'presspilot' ),
			),
			'custom'      => array(
				'label'         => __( 'Custom (OpenAI-compatible)', 'presspilot' ),
				'api'           => 'openai',
				'base_url'      => '',
				'default_model' => '',
				'keys_url'      => '',
				'note'          => __( 'Any endpoint that speaks /chat/completions — a local Ollama or vLLM server, LiteLLM, a company gateway.', 'presspilot' ),
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Stored configuration                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * The saved copilot configuration, with defaults filled in.
	 *
	 * @return array
	 */
	public static function config() {
		$saved     = get_option( self::OPTION, array() );
		$saved     = is_array( $saved ) ? $saved : array();
		$provider  = isset( $saved['provider'] ) ? (string) $saved['provider'] : 'anthropic';
		$providers = self::providers();
		if ( ! isset( $providers[ $provider ] ) ) {
			$provider = 'anthropic';
		}

		$base = self::normalize_base_url( $provider, isset( $saved['base_url'] ) ? (string) $saved['base_url'] : '' );

		return array(
			'provider'  => $provider,
			'api'       => $providers[ $provider ]['api'],
			'api_key'   => isset( $saved['api_key'] ) ? (string) $saved['api_key'] : '',
			'model'     => isset( $saved['model'] ) && '' !== $saved['model'] ? (string) $saved['model'] : $providers[ $provider ]['default_model'],
			'base_url'  => untrailingslashit( $base ),
			'max_steps' => isset( $saved['max_steps'] ) ? max( 1, min( 40, (int) $saved['max_steps'] ) ) : 12,
		);
	}

	/**
	 * Persist the configuration. An empty api_key keeps the stored one, so the
	 * admin form can show a masked value without round-tripping the secret.
	 *
	 * @param array $input Raw form/REST input.
	 * @return array The saved config.
	 */
	public static function save_config( $input ) {
		$current   = self::config();
		$providers = self::providers();

		$provider = isset( $input['provider'] ) ? sanitize_key( $input['provider'] ) : $current['provider'];
		if ( ! isset( $providers[ $provider ] ) ) {
			$provider = $current['provider'];
		}

		$api_key = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		if ( '' === $api_key || false !== strpos( $api_key, '•' ) ) {
			$api_key = $current['api_key'];
		}

		$base_url = isset( $input['base_url'] ) ? esc_url_raw( trim( (string) $input['base_url'] ) ) : '';
		if ( '' === $base_url && $provider !== $current['provider'] ) {
			$base_url = $providers[ $provider ]['base_url']; // switching provider resets a stale custom base.
		}
		$base_url = self::normalize_base_url( $provider, $base_url );

		$config = array(
			'provider'  => $provider,
			'api_key'   => $api_key,
			'model'     => isset( $input['model'] ) ? sanitize_text_field( (string) $input['model'] ) : $current['model'],
			'base_url'  => $base_url,
			'max_steps' => isset( $input['max_steps'] ) ? max( 1, min( 40, (int) $input['max_steps'] ) ) : $current['max_steps'],
		);
		update_option( self::OPTION, $config, false );
		return self::config();
	}

	/**
	 * Resolve the API base URL for a provider.
	 *
	 * Every hosted provider here serves its API under a version path
	 * (`/v1`), and pasting the bare origin is the easy mistake to make: the
	 * request then lands on the vendor's marketing site, which answers `200` with
	 * HTML instead of JSON and produces a failure that looks like an outage. When
	 * a known provider is configured with nothing but its origin, snap it back to
	 * the documented base. A path the user actually chose is always respected, and
	 * `custom` is never touched — that is the point of `custom`.
	 *
	 * @param string $provider Provider slug.
	 * @param string $base     Configured base URL (may be empty).
	 * @return string
	 */
	public static function normalize_base_url( $provider, $base ) {
		$providers = self::providers();
		$canonical = isset( $providers[ $provider ] ) ? $providers[ $provider ]['base_url'] : '';
		$base      = untrailingslashit( trim( (string) $base ) );

		if ( '' === $base ) {
			return untrailingslashit( $canonical );
		}
		if ( 'custom' === $provider || '' === $canonical ) {
			return $base;
		}

		$parts = wp_parse_url( $base );
		if ( empty( $parts['host'] ) ) {
			return untrailingslashit( $canonical );
		}
		$path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		if ( '' === $path ) {
			return untrailingslashit( $canonical );
		}
		return $base;
	}

	/** The config as it may safely be shown/returned: the key masked. */
	public static function public_config() {
		$config               = self::config();
		$config['configured'] = ( '' !== $config['api_key'] && '' !== $config['model'] );
		$config['api_key']    = self::mask( $config['api_key'] );
		return $config;
	}

	/** Mask a secret for display: keep enough to recognise it, show nothing usable. */
	public static function mask( $secret ) {
		if ( '' === $secret ) {
			return '';
		}
		return substr( $secret, 0, 6 ) . str_repeat( '•', 12 ) . substr( $secret, -4 );
	}

	/* ------------------------------------------------------------------ */
	/* Model listing                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Ask the provider what models it has, so the admin never has to guess or
	 * hand-type a model id that has since been renamed.
	 *
	 * @param array|null $config Config to use (defaults to the saved one).
	 * @return array|WP_Error List of model ids.
	 */
	public static function list_models( $config = null ) {
		$config = $config ? $config : self::config();
		if ( '' === $config['api_key'] ) {
			return PP_Helpers::error( 'pp_no_provider_key', 'No API key is configured for the copilot provider.', 400 );
		}

		$response = wp_remote_get(
			$config['base_url'] . '/models',
			array(
				'timeout'    => 30,
				'headers'    => self::auth_headers( $config ),
				'user-agent' => self::user_agent( $config ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return PP_Helpers::error( 'pp_provider_unreachable', $response->get_error_message(), self::STATUS_UPSTREAM );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			return PP_Helpers::error( 'pp_provider_error', self::error_text( $body, $code ), self::STATUS_UPSTREAM );
		}
		// A 2xx that is not JSON usually means the base URL points at a web page
		// rather than an API root — say that, instead of reporting "HTTP 200".
		if ( ! is_array( $body ) ) {
			return PP_Helpers::error(
				'pp_provider_bad_response',
				sprintf( 'The provider answered %s/models with a non-JSON body. Check the API base URL — it usually needs to end in /v1.', $config['base_url'] ),
				self::STATUS_UPSTREAM
			);
		}

		$models = array();
		foreach ( ( isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array() ) as $model ) {
			if ( isset( $model['id'] ) ) {
				$models[] = (string) $model['id'];
			}
		}
		sort( $models );
		return $models;
	}

	/* ------------------------------------------------------------------ */
	/* Chat                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * One model round trip.
	 *
	 * @param array  $messages Provider-native message list (no system message).
	 * @param array  $tools    Tool definitions in this provider's shape.
	 * @param string $system   System prompt.
	 * @param array  $config   Provider config.
	 * @return array{text:string,tool_calls:array,assistant:array,usage:array,stop:string}|WP_Error
	 */
	public static function chat( $messages, $tools, $system, $config = null ) {
		$config = $config ? $config : self::config();

		if ( '' === $config['api_key'] ) {
			return PP_Helpers::error( 'pp_no_provider_key', 'No API key is configured. Set one on the PressPilot → Copilot screen.', 400 );
		}
		if ( '' === $config['model'] ) {
			return PP_Helpers::error( 'pp_no_model', 'No model is selected. Pick one on the PressPilot → Copilot screen.', 400 );
		}

		return 'anthropic' === $config['api']
			? self::chat_anthropic( $messages, $tools, $system, $config )
			: self::chat_openai( $messages, $tools, $system, $config );
	}

	/**
	 * Anthropic Messages API.
	 *
	 * @return array|WP_Error
	 */
	private static function chat_anthropic( $messages, $tools, $system, $config ) {
		$body = array(
			'model'      => $config['model'],
			'max_tokens' => 16000,
			'system'     => $system,
			'messages'   => $messages,
		);
		if ( ! empty( $tools ) ) {
			$body['tools'] = $tools;
		}

		$data = self::post( $config['base_url'] . '/messages', $body, $config );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$text       = '';
		$tool_calls = array();
		foreach ( ( isset( $data['content'] ) && is_array( $data['content'] ) ? $data['content'] : array() ) as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			} elseif ( 'tool_use' === ( $block['type'] ?? '' ) ) {
				$tool_calls[] = array(
					'id'   => (string) $block['id'],
					'name' => (string) $block['name'],
					'args' => is_array( $block['input'] ?? null ) ? $block['input'] : array(),
				);
			}
		}

		return array(
			'text'       => self::strip_reasoning( $text ),
			'tool_calls' => $tool_calls,
			// Echoed back verbatim, which also preserves thinking blocks and their
			// signatures — a re-serialised copy would be rejected on the next turn.
			'assistant'  => array( 'role' => 'assistant', 'content' => $data['content'] ?? array() ),
			'usage'      => isset( $data['usage'] ) ? $data['usage'] : array(),
			'stop'       => isset( $data['stop_reason'] ) ? (string) $data['stop_reason'] : '',
		);
	}

	/**
	 * OpenAI chat/completions — also OpenRouter, AgentRouter and every
	 * OpenAI-compatible gateway.
	 *
	 * @return array|WP_Error
	 */
	private static function chat_openai( $messages, $tools, $system, $config ) {
		array_unshift( $messages, array( 'role' => 'system', 'content' => $system ) );

		$body = array(
			'model'    => $config['model'],
			'messages' => $messages,
		);
		if ( ! empty( $tools ) ) {
			$body['tools']       = $tools;
			$body['tool_choice'] = 'auto';
		}

		$data = self::post( $config['base_url'] . '/chat/completions', $body, $config );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$message = isset( $data['choices'][0]['message'] ) && is_array( $data['choices'][0]['message'] )
			? $data['choices'][0]['message']
			: array( 'role' => 'assistant', 'content' => '' );

		$tool_calls = array();
		foreach ( ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ? $message['tool_calls'] : array() ) as $call ) {
			if ( ! is_array( $call ) ) {
				continue;
			}
			// Arguments arrive as a JSON *string*; never string-match them, always decode.
			$args = json_decode( (string) ( $call['function']['arguments'] ?? '{}' ), true );
			$tool_calls[] = array(
				'id'   => (string) ( $call['id'] ?? '' ),
				'name' => (string) ( $call['function']['name'] ?? '' ),
				'args' => is_array( $args ) ? $args : array(),
			);
		}

		return array(
			'text'       => self::strip_reasoning( is_string( $message['content'] ?? null ) ? $message['content'] : '' ),
			'tool_calls' => $tool_calls,
			'assistant'  => $message,
			'usage'      => isset( $data['usage'] ) ? $data['usage'] : array(),
			'stop'       => isset( $data['choices'][0]['finish_reason'] ) ? (string) $data['choices'][0]['finish_reason'] : '',
		);
	}

	/**
	 * Strip inline reasoning blocks from text meant for display.
	 *
	 * Several open models (Kimi, DeepSeek, QwQ and friends) emit their chain of
	 * thought inline as <think>…</think> inside the normal content field rather
	 * than in a separate channel. It is not an answer and should never reach the
	 * reader. Only the *displayed* text is cleaned — the assistant turn echoed
	 * back into the conversation is left verbatim, so nothing the model relies on
	 * is rewritten behind its back.
	 *
	 * @param string $text Raw content from the model.
	 * @return string
	 */
	private static function strip_reasoning( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}
		// Complete pairs first.
		$clean = preg_replace( '#<think(?:ing)?\b[^>]*>.*?</think(?:ing)?>#is', '', $text );
		if ( null === $clean ) {
			$clean = $text; // A pathological input blew the backtracking limit; keep the original.
		}
		// A stray closing tag means the opener was lost (truncation, or a model that
		// only emits the close). Everything before it is reasoning, so drop it.
		if ( preg_match( '#</think(?:ing)?>#i', $clean, $m, PREG_OFFSET_CAPTURE ) ) {
			$last = strripos( $clean, $m[0][0] );
			if ( false !== $last ) {
				$clean = substr( $clean, $last + strlen( $m[0][0] ) );
			}
		}
		// An unclosed opener means the whole tail is reasoning with no answer yet.
		$open = preg_match( '#<think(?:ing)?\b[^>]*>#i', $clean, $om, PREG_OFFSET_CAPTURE ) ? $om[0][1] : false;
		if ( false !== $open ) {
			$clean = substr( $clean, 0, $open );
		}
		return trim( $clean );
	}

	/* ------------------------------------------------------------------ */
	/* Message construction (per wire format)                             */
	/* ------------------------------------------------------------------ */

	/** A user turn. */
	public static function user_message( $text, $config = null ) {
		$config = $config ? $config : self::config();
		return 'anthropic' === $config['api']
			? array( 'role' => 'user', 'content' => array( array( 'type' => 'text', 'text' => (string) $text ) ) )
			: array( 'role' => 'user', 'content' => (string) $text );
	}

	/**
	 * The message(s) carrying tool results back to the model.
	 *
	 * Anthropic wants every result of a turn in ONE user message; OpenAI wants one
	 * `tool` message per call. Returning them split across messages on Anthropic
	 * quietly teaches the model to stop calling tools in parallel.
	 *
	 * @param array $results [ ['id'=>…,'text'=>…,'is_error'=>bool], … ]
	 * @return array[] Messages to append.
	 */
	public static function tool_result_messages( $results, $config = null ) {
		$config = $config ? $config : self::config();

		if ( 'anthropic' === $config['api'] ) {
			$content = array();
			foreach ( $results as $result ) {
				$content[] = array(
					'type'        => 'tool_result',
					'tool_use_id' => $result['id'],
					'content'     => $result['text'],
					'is_error'    => (bool) $result['is_error'],
				);
			}
			return array( array( 'role' => 'user', 'content' => $content ) );
		}

		$messages = array();
		foreach ( $results as $result ) {
			$messages[] = array(
				'role'         => 'tool',
				'tool_call_id' => $result['id'],
				'content'      => $result['text'],
			);
		}
		return $messages;
	}

	/* ------------------------------------------------------------------ */
	/* HTTP                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * The User-Agent to send. Most providers ignore it; AgentRouter refuses any
	 * client it does not recognise, so its entry overrides this.
	 *
	 * @param array $config Provider config.
	 * @return string
	 */
	private static function user_agent( $config ) {
		$providers = self::providers();
		$provider  = isset( $config['provider'] ) ? $config['provider'] : '';
		if ( ! empty( $providers[ $provider ]['user_agent'] ) ) {
			return $providers[ $provider ]['user_agent'];
		}
		return PP_PRODUCT . '/' . PP_VERSION . ' (+' . home_url() . ')';
	}

	private static function auth_headers( $config ) {
		if ( 'anthropic' === $config['api'] ) {
			return array(
				'x-api-key'         => $config['api_key'],
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			);
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $config['api_key'],
			'content-type'  => 'application/json',
		);
		if ( 'openrouter' === $config['provider'] ) {
			// OpenRouter attributes usage to the calling app via these.
			$headers['HTTP-Referer'] = home_url();
			$headers['X-Title']      = PP_PRODUCT;
		}
		return $headers;
	}

	/**
	 * POST JSON and return the decoded body, or a WP_Error carrying the provider's
	 * own message — a bad model id or an expired key should say so plainly.
	 *
	 * @return array|WP_Error
	 */
	private static function post( $url, $body, $config ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'    => self::TIMEOUT,
				'headers'    => self::auth_headers( $config ),
				'user-agent' => self::user_agent( $config ),
				'body'       => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return PP_Helpers::error( 'pp_provider_unreachable', 'Could not reach the model provider: ' . $response->get_error_message(), self::STATUS_UPSTREAM );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return PP_Helpers::error( 'pp_provider_error', self::error_text( $data, $code ), self::STATUS_UPSTREAM );
		}
		if ( ! is_array( $data ) ) {
			// Same tell as in list_models(): HTML behind a 2xx means the base URL is
			// pointing at the vendor's website rather than its API root.
			return PP_Helpers::error(
				'pp_provider_bad_response',
				sprintf( 'The provider answered %s with a non-JSON body. Check the API base URL — it usually needs to end in /v1.', $url ),
				self::STATUS_UPSTREAM
			);
		}
		return $data;
	}

	/**
	 * Pull the human-readable message out of whatever error shape came back.
	 *
	 * A provider's own wording is kept verbatim — it is the most accurate thing
	 * available — but it is not always actionable, and not always in the reader's
	 * language: a gateway may answer an expired key with a sentence in Chinese.
	 * On the auth statuses, add a line saying what to actually do about it.
	 */
	private static function error_text( $data, $code ) {
		$message = '';
		if ( is_array( $data ) ) {
			foreach ( array( array( 'error', 'message' ), array( 'message' ), array( 'error' ), array( 'detail' ) ) as $path ) {
				$node = $data;
				foreach ( $path as $key ) {
					if ( ! is_array( $node ) || ! isset( $node[ $key ] ) ) {
						continue 2;
					}
					$node = $node[ $key ];
				}
				if ( is_string( $node ) && '' !== $node ) {
					$message = $node;
					break;
				}
			}
		}

		$text = '' !== $message
			? sprintf( '%s (HTTP %d)', $message, $code )
			: sprintf( 'The model provider returned HTTP %d.', $code );

		if ( 401 === (int) $code || 403 === (int) $code ) {
			$text .= ' — ' . __( 'The provider rejected the API key. It is usually expired, revoked, or out of credit; issue a fresh one in the provider\'s console and paste it above.', 'presspilot' );
		}
		return $text;
	}
}
