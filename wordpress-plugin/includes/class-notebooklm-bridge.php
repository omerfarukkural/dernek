<?php
/**
 * NotebookLM → Google Drive → Claude Knowledge Bridge
 *
 * Saves research to Google Drive via Apps Script, retrieves context
 * for future use, and builds Claude prompts enriched with past research.
 *
 * @package Dernek
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dernek_NotebookLM_Bridge {

	const APPS_SCRIPT_URL = 'https://script.google.com/macros/s/AKfycbwRlqMSNbjsLaLkAdCZmFLvBIAnZAdeyegjbdWbkL3P-Pty2ruznNaampwqKKcyXYX6/exec';
	const WEBHOOK_SECRET  = '571632';
	const CACHE_GROUP     = 'dernek_notebooklm';
	const CACHE_TTL       = 3600; // 1 hour

	/** @var self|null */
	private static $instance = null;

	/** Get singleton instance. */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Constructor — register REST endpoint. */
	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/** Register the save-research webhook endpoint. */
	public function register_rest_routes(): void {
		register_rest_route( 'dernek/v1', '/save-research', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_save_research' ],
			'permission_callback' => '__return_true', // Verified via WEBHOOK_SECRET
			'args'                => [
				'content' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				],
				'topic'   => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'source'  => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => 'unknown',
				],
				'secret'  => [
					'required' => true,
					'type'     => 'string',
				],
			],
		] );
	}

	// -------------------------------------------------------------------------
	// REST Webhook
	// -------------------------------------------------------------------------

	/**
	 * REST endpoint: receive research content from any AI tool and save to Drive.
	 */
	public function rest_save_research( WP_REST_Request $request ): WP_REST_Response {
		// Verify secret — accept via body param or header
		$secret = $request->get_param( 'secret' ) ?: $request->get_header( 'X-Webhook-Secret' );

		if ( $secret !== self::WEBHOOK_SECRET ) {
			return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
		}

		$content = $request->get_param( 'content' );
		$topic   = $request->get_param( 'topic' );
		$source  = $request->get_param( 'source' ) ?: 'api';

		$result = $this->save_research_to_drive( $content, $topic, $source );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			], 500 );
		}

		// Bust the context cache for this topic so next request fetches fresh data
		wp_cache_delete( $this->cache_key( $topic ), self::CACHE_GROUP );

		return new WP_REST_Response( [
			'success'  => true,
			'topic'    => $topic,
			'source'   => $source,
			'drive_id' => $result['fileId'] ?? null,
		], 201 );
	}

	// -------------------------------------------------------------------------
	// Core Methods
	// -------------------------------------------------------------------------

	/**
	 * Save research content to Google Drive via Apps Script webhook.
	 *
	 * @param string $content Raw research text or JSON.
	 * @param string $topic   Topic/keyword for categorization.
	 * @param string $source  Source tool: notebooklm|perplexity|gemini|manual|api
	 * @return array|\WP_Error Apps Script response on success.
	 */
	public function save_research_to_drive( string $content, string $topic, string $source = 'notebooklm' ): array|WP_Error {
		if ( empty( $content ) || empty( $topic ) ) {
			return new WP_Error( 'invalid_input', 'İçerik ve konu boş olamaz.' );
		}

		$payload = [
			'action'     => 'saveResearch',
			'secret'     => self::WEBHOOK_SECRET,
			'topic'      => sanitize_text_field( $topic ),
			'source'     => sanitize_text_field( $source ),
			'content'    => $content,
			'timestamp'  => wp_date( 'Y-m-d H:i:s' ),
			'site_url'   => get_site_url(),
			'word_count' => str_word_count( wp_strip_all_tags( $content ) ),
		];

		$response = wp_remote_post( self::APPS_SCRIPT_URL, [
			'timeout' => 30,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'apps_script_error', $body['error'] ?? "Apps Script HTTP {$code}" );
		}

		if ( ! empty( $body['error'] ) ) {
			return new WP_Error( 'apps_script_logic_error', $body['error'] );
		}

		return $body ?? [ 'success' => true ];
	}

	/**
	 * Query Apps Script for similar past research on a given topic.
	 *
	 * @param string $topic
	 * @return array Array of research entries: [{topic, source, content, timestamp, fileId}]
	 */
	public function get_context_for_topic( string $topic ): array {
		$cache_key = $this->cache_key( $topic );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$url = add_query_arg( [
			'action' => 'getResearch',
			'topic'  => rawurlencode( $topic ),
			'secret' => self::WEBHOOK_SECRET,
			'limit'  => 5,
		], self::APPS_SCRIPT_URL );

		$response = wp_remote_get( $url, [
			'timeout' => 20,
			'headers' => [ 'Accept' => 'application/json' ],
		] );

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return [];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = $body['results'] ?? [];

		// Cache result
		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Build a Claude prompt that prepends relevant NotebookLM context.
	 *
	 * This reduces token usage by providing curated past research
	 * rather than re-running the full research pipeline.
	 *
	 * @param string $prompt The user's original prompt for Claude.
	 * @param string $topic  Topic used to look up past research context.
	 * @return string Enhanced prompt with context prepended.
	 */
	public function build_claude_prompt_with_context( string $prompt, string $topic ): string {
		$context_entries = $this->get_context_for_topic( $topic );

		if ( empty( $context_entries ) ) {
			return $prompt;
		}

		$context_parts = [];

		foreach ( array_slice( $context_entries, 0, 3 ) as $entry ) {
			$source    = $entry['source']    ?? 'bilinmeyen';
			$timestamp = $entry['timestamp'] ?? '';
			$content   = $entry['content']   ?? '';

			if ( empty( $content ) ) {
				continue;
			}

			// Truncate individual entries to save tokens
			$truncated       = mb_substr( wp_strip_all_tags( $content ), 0, 800 );
			$context_parts[] = "### Kaynak: {$source}" . ( $timestamp ? " ({$timestamp})" : '' ) . "\n{$truncated}";
		}

		if ( empty( $context_parts ) ) {
			return $prompt;
		}

		$context_block = "## Geçmiş Araştırma Bağlamı (NotebookLM/Drive)\n\n"
			. "Konu: *{$topic}*\n\n"
			. implode( "\n\n---\n\n", $context_parts )
			. "\n\n---\n\n"
			. "Yukarıdaki geçmiş araştırma bağlamını göz önünde bulundur, ancak tüm görevi aşağıdaki talimata göre yürüt:\n\n";

		return $context_block . $prompt;
	}

	/**
	 * Retrieve all stored research topics summary from Drive.
	 *
	 * @return array List of topics: [{topic, count, last_updated}]
	 */
	public function list_research_topics(): array {
		$url = add_query_arg( [
			'action' => 'listTopics',
			'secret' => self::WEBHOOK_SECRET,
		], self::APPS_SCRIPT_URL );

		$response = wp_remote_get( $url, [
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return $body['topics'] ?? [];
	}

	/**
	 * Save current pipeline research output and raw NotebookLM notes to Drive.
	 * Convenience wrapper that handles both research array and free-text notes.
	 *
	 * @param array|string $research     Structured research array or raw text.
	 * @param string       $topic
	 * @param string       $source       notebooklm|perplexity|gemini|manual
	 * @return array|\WP_Error
	 */
	public function save_pipeline_research( array|string $research, string $topic, string $source = 'perplexity' ): array|WP_Error {
		if ( is_array( $research ) ) {
			$content = wp_json_encode( $research, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		} else {
			$content = $research;
		}

		return $this->save_research_to_drive( $content, $topic, $source );
	}

	/**
	 * Format context entries into a readable Markdown string for UI display.
	 *
	 * @param string $topic
	 * @return string Formatted Markdown.
	 */
	public function get_formatted_context( string $topic ): string {
		$entries = $this->get_context_for_topic( $topic );

		if ( empty( $entries ) ) {
			return '*Bu konu için henüz kayıtlı araştırma yok.*';
		}

		$output = "## 📚 Geçmiş Araştırmalar: {$topic}\n\n";

		foreach ( $entries as $i => $entry ) {
			$num       = $i + 1;
			$source    = esc_html( $entry['source']    ?? 'Bilinmeyen' );
			$timestamp = esc_html( $entry['timestamp'] ?? '' );
			$content   = wp_strip_all_tags( $entry['content'] ?? '' );
			$preview   = mb_substr( $content, 0, 300 );

			$output .= "### {$num}. {$source}" . ( $timestamp ? " — {$timestamp}" : '' ) . "\n";
			$output .= $preview . ( mb_strlen( $content ) > 300 ? '...' : '' ) . "\n\n";
		}

		return $output;
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/**
	 * Generate a cache key for a given topic.
	 */
	private function cache_key( string $topic ): string {
		return 'research_' . md5( strtolower( trim( $topic ) ) );
	}
}
