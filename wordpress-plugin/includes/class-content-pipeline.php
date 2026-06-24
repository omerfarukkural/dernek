<?php
/**
 * AI Content Pipeline Class
 *
 * Orchestrates: Perplexity research → Gemini generation → Claude refinement
 * then creates social_post CPT entries and triggers Telegram approval.
 *
 * @package Dernek
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dernek_Content_Pipeline {

	const PERPLEXITY_API_URL = 'https://api.perplexity.ai/chat/completions';
	const GEMINI_API_URL     = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
	const CLAUDE_API_URL     = 'https://api.anthropic.com/v1/messages';
	const CLAUDE_MODEL       = 'claude-sonnet-4-6';
	const PERPLEXITY_MODEL   = 'sonar-pro';

	/** Platform character limits */
	const CHAR_LIMITS = [
		'threads'   => 500,
		'facebook'  => 2000,
		'instagram' => 2200,
		'twitter'   => 280,
	];

	/** Account-specific tone guidelines */
	const TONE_GUIDELINES = [
		'personal' => 'Samimi, düşündürücü, kişisel deneyim odaklı. Türkçe. Ömer Faruk\'un sesini yansıt.',
		'dernek'   => 'Kurumsal, güvenilir, toplumu bilgilendirici. Türkçe. Sivil toplum dili.',
		'viral'    => 'Dikkat çekici, viral hook ile başla, trend\'e uygun, emoji ile zenginleştir. Türkçe.',
	];

	/** @var self|null */
	private static $instance = null;

	/** Get singleton instance. */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Constructor — register REST endpoints. */
	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/** Register REST routes for pipeline invocation. */
	public function register_rest_routes(): void {
		register_rest_route( 'dernek/v1', '/pipeline/run', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_run_pipeline' ],
			'permission_callback' => [ 'Dernek_REST_API', 'auth' ],
			'args' => [
				'topic'        => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'account_type' => [
					'required' => true,
					'type'     => 'string',
					'enum'     => [ 'personal', 'dernek', 'viral' ],
				],
				'platforms'    => [
					'required' => true,
					'type'     => 'array',
					'items'    => [
						'type' => 'string',
						'enum' => [ 'threads', 'facebook', 'instagram', 'twitter' ],
					],
				],
				'scheduled_at' => [
					'required' => false,
					'type'     => 'string',
				],
			],
		] );
	}

	/** REST: Run the full pipeline. */
	public function rest_run_pipeline( WP_REST_Request $request ): WP_REST_Response {
		$topic        = $request->get_param( 'topic' );
		$account_type = $request->get_param( 'account_type' );
		$platforms    = (array) $request->get_param( 'platforms' );
		$scheduled_at = $request->get_param( 'scheduled_at' );

		$result = $this->create_pipeline( $topic, $platforms, $account_type, $scheduled_at );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );
		}

		return new WP_REST_Response( $result, 201 );
	}

	// -------------------------------------------------------------------------
	// Step 1: Research with Perplexity
	// -------------------------------------------------------------------------

	/**
	 * Research a topic using Perplexity sonar-pro.
	 *
	 * @param string $topic
	 * @param string $account_type personal|dernek|viral
	 * @return array|\WP_Error Decoded JSON response.
	 */
	public function research_with_perplexity( string $topic, string $account_type ): array|WP_Error {
		$api_key = get_option( 'dernek_perplexity_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'perplexity_config', 'Perplexity API anahtarı tanımlı değil.' );
		}

		$tone       = self::TONE_GUIDELINES[ $account_type ] ?? self::TONE_GUIDELINES['personal'];
		$system_msg = "Sen bir sosyal medya araştırmacısısın. Verilen konuyu araştır ve yapılandırılmış JSON çıktısı üret. Hedef: {$tone}";

		$user_msg = "Konu: {$topic}\n\n"
			. "Lütfen şu başlıkları içeren JSON formatında araştırma yap:\n"
			. "{\n"
			. "  \"topic\": \"{$topic}\",\n"
			. "  \"summary\": \"Konunun 2-3 cümlelik özeti\",\n"
			. "  \"key_points\": [\"nokta1\", \"nokta2\", \"nokta3\"],\n"
			. "  \"statistics\": [\"istatistik1\", \"istatistik2\"],\n"
			. "  \"sources\": [\"kaynak1\", \"kaynak2\"],\n"
			. "  \"hashtags\": [\"#hashtag1\", \"#hashtag2\"],\n"
			. "  \"trending_angle\": \"Viral potansiyeli olan açı\",\n"
			. "  \"local_relevance\": \"Türkiye veya sivil toplum için önemi\"\n"
			. "}";

		$response = wp_remote_post( self::PERPLEXITY_API_URL, [
			'timeout' => 60,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
			'body' => wp_json_encode( [
				'model'    => self::PERPLEXITY_MODEL,
				'messages' => [
					[ 'role' => 'system', 'content' => $system_msg ],
					[ 'role' => 'user',   'content' => $user_msg ],
				],
				'temperature'        => 0.2,
				'max_tokens'         => 2000,
				'return_citations'   => true,
				'search_domain_filter' => [],
				'return_images'      => false,
				'search_recency_filter' => 'week',
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			return new WP_Error( 'perplexity_api_error', $body['error']['message'] ?? "Perplexity HTTP {$code}" );
		}

		$content = $body['choices'][0]['message']['content'] ?? '';

		// Extract JSON from markdown code block if present
		if ( preg_match( '/```(?:json)?\s*([\s\S]+?)\s*```/', $content, $m ) ) {
			$content = $m[1];
		}

		$research = json_decode( $content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Return raw content wrapped in array if JSON parsing fails
			$research = [
				'topic'            => $topic,
				'summary'          => $content,
				'key_points'       => [],
				'statistics'       => [],
				'sources'          => $body['citations'] ?? [],
				'hashtags'         => [],
				'trending_angle'   => '',
				'local_relevance'  => '',
				'raw'              => $content,
			];
		}

		// Attach Perplexity citations if available
		if ( ! empty( $body['citations'] ) && empty( $research['sources'] ) ) {
			$research['sources'] = $body['citations'];
		}

		return $research;
	}

	// -------------------------------------------------------------------------
	// Step 2: Generate content with Gemini
	// -------------------------------------------------------------------------

	/**
	 * Generate platform-specific content variants using Gemini 2.0 Flash.
	 *
	 * @param array  $research     Output from research_with_perplexity().
	 * @param string $platform     threads|facebook|instagram|twitter
	 * @param string $account_type personal|dernek|viral
	 * @return array|\WP_Error Array with 'variants' key containing 3 content options.
	 */
	public function generate_with_gemini( array $research, string $platform, string $account_type ): array|WP_Error {
		$api_key = get_option( 'dernek_gemini_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'gemini_config', 'Gemini API anahtarı tanımlı değil.' );
		}

		$char_limit = self::CHAR_LIMITS[ $platform ] ?? 500;
		$tone       = self::TONE_GUIDELINES[ $account_type ] ?? self::TONE_GUIDELINES['personal'];

		$platform_hints = [
			'threads'   => 'Kısa, düşündürücü, konuşma tarzında. Thread zinciri önerisi yapabilirsin.',
			'facebook'  => 'Daha uzun, hikaye anlatıcı, bağlantı paylaşımına uygun.',
			'instagram' => 'Görsel odaklı, caption formatında, emoji ile zenginleştirilmiş, hashtag listesi sona eklenmiş.',
			'twitter'   => 'Ultra kısa, güçlü hook, maksimum etki için sıkıştırılmış.',
		];

		$hint     = $platform_hints[ $platform ] ?? '';
		$research_json = wp_json_encode( $research, JSON_UNESCAPED_UNICODE );

		$prompt = "Aşağıdaki araştırmayı kullanarak {$platform} için sosyal medya içeriği üret.\n\n"
			. "TON: {$tone}\n"
			. "PLATFORM İPUCU: {$hint}\n"
			. "KARAKTER LİMİTİ: Maksimum {$char_limit} karakter\n\n"
			. "ARAŞTIRMA:\n{$research_json}\n\n"
			. "3 farklı içerik varyantı üret. JSON formatında döndür:\n"
			. "{\n"
			. "  \"platform\": \"{$platform}\",\n"
			. "  \"account_type\": \"{$account_type}\",\n"
			. "  \"variants\": [\n"
			. "    {\"id\": 1, \"content\": \"...\", \"hook\": \"İlk cümle\", \"char_count\": 0},\n"
			. "    {\"id\": 2, \"content\": \"...\", \"hook\": \"İlk cümle\", \"char_count\": 0},\n"
			. "    {\"id\": 3, \"content\": \"...\", \"hook\": \"İlk cümle\", \"char_count\": 0}\n"
			. "  ]\n"
			. "}";

		$url      = self::GEMINI_API_URL . '?key=' . $api_key;
		$response = wp_remote_post( $url, [
			'timeout' => 60,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'contents' => [
					[
						'role'  => 'user',
						'parts' => [ [ 'text' => $prompt ] ],
					],
				],
				'generationConfig' => [
					'temperature'     => 0.8,
					'maxOutputTokens' => 3000,
					'responseMimeType' => 'application/json',
				],
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $body['error']['message'] ?? "Gemini HTTP {$code}";
			return new WP_Error( 'gemini_api_error', $msg );
		}

		$raw_text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

		if ( preg_match( '/```(?:json)?\s*([\s\S]+?)\s*```/', $raw_text, $m ) ) {
			$raw_text = $m[1];
		}

		$result = json_decode( $raw_text, true );

		if ( json_last_error() !== JSON_ERROR_NONE || empty( $result['variants'] ) ) {
			return new WP_Error( 'gemini_parse_error', 'Gemini yanıtı JSON olarak ayrıştırılamadı.' );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Step 3: Refine with Claude
	// -------------------------------------------------------------------------

	/**
	 * Polish and refine content using Claude claude-sonnet-4-6.
	 *
	 * @param string $content         Raw content to refine.
	 * @param string $tone_guidelines Account-specific tone guidelines.
	 * @return string|\WP_Error Refined content string.
	 */
	public function refine_with_claude( string $content, string $tone_guidelines ): string|WP_Error {
		$api_key = get_option( 'dernek_claude_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'claude_config', 'Claude API anahtarı tanımlı değil.' );
		}

		$system = "Sen bir sosyal medya editörüsün. Verilen içeriği ton kılavuzuna göre iyileştir. "
			. "Sadece iyileştirilmiş içeriği döndür, açıklama ekleme. Türkçe yaz.";

		$user = "TON KILAVUZU: {$tone_guidelines}\n\n"
			. "İÇERİK:\n{$content}\n\n"
			. "Bu içeriği ton kılavuzuna göre iyileştir. "
			. "Doğallık, akıcılık ve özgünlük için düzenle. "
			. "Karakter sayısını korumaya çalış. Sadece iyileştirilmiş metni döndür.";

		$response = wp_remote_post( self::CLAUDE_API_URL, [
			'timeout' => 45,
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			],
			'body' => wp_json_encode( [
				'model'      => self::CLAUDE_MODEL,
				'max_tokens' => 1024,
				'system'     => $system,
				'messages'   => [
					[ 'role' => 'user', 'content' => $user ],
				],
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $body['error']['message'] ?? "Claude HTTP {$code}";
			return new WP_Error( 'claude_api_error', $msg );
		}

		return trim( $body['content'][0]['text'] ?? $content );
	}

	// -------------------------------------------------------------------------
	// Master Pipeline
	// -------------------------------------------------------------------------

	/**
	 * Run the full AI pipeline: Research → Generate → Refine → Create CPT entries.
	 *
	 * @param string      $topic        Topic to research.
	 * @param string[]    $platforms    Array of platform slugs.
	 * @param string      $account_type personal|dernek|viral
	 * @param string|null $scheduled_at ISO 8601 datetime or null for +1 hour.
	 * @return array|\WP_Error Array of created post IDs keyed by platform.
	 */
	public function create_pipeline( string $topic, array $platforms, string $account_type, ?string $scheduled_at = null ): array|WP_Error {
		$scheduled_at = $scheduled_at ?: wp_date( 'c', strtotime( '+1 hour' ) );
		$tone         = self::TONE_GUIDELINES[ $account_type ] ?? self::TONE_GUIDELINES['personal'];

		// --- Step 1: Research ---
		$research = $this->research_with_perplexity( $topic, $account_type );
		if ( is_wp_error( $research ) ) {
			return $research;
		}

		$created_posts = [];
		$errors        = [];

		foreach ( $platforms as $platform ) {
			$platform = sanitize_key( $platform );
			if ( ! array_key_exists( $platform, self::CHAR_LIMITS ) ) {
				continue;
			}

			// --- Step 2: Generate ---
			$generated = $this->generate_with_gemini( $research, $platform, $account_type );
			if ( is_wp_error( $generated ) ) {
				$errors[ $platform ] = $generated->get_error_message();
				continue;
			}

			// Use the first variant as primary content
			$best_variant = $generated['variants'][0]['content'] ?? '';
			if ( empty( $best_variant ) ) {
				$errors[ $platform ] = 'Gemini içerik varyantı boş döndü.';
				continue;
			}

			// --- Step 3: Refine ---
			$refined = $this->refine_with_claude( $best_variant, $tone );
			if ( is_wp_error( $refined ) ) {
				// Use unrefined content as fallback
				$refined = $best_variant;
			}

			// Enforce character limit
			$char_limit = self::CHAR_LIMITS[ $platform ];
			if ( mb_strlen( $refined ) > $char_limit ) {
				$refined = mb_substr( $refined, 0, $char_limit );
			}

			// --- Step 4: Create CPT entry + Telegram approval ---
			$scheduler = Dernek_Social_Scheduler::get_instance();
			$post_id   = $scheduler->schedule_post( [
				'title'        => mb_substr( $topic, 0, 60 ) . " [{$platform}]",
				'content'      => $refined,
				'platform'     => $platform,
				'account_type' => $account_type,
				'scheduled_at' => $scheduled_at,
				'ai_tool_used' => 'perplexity+gemini+claude',
			] );

			if ( is_wp_error( $post_id ) ) {
				$errors[ $platform ] = $post_id->get_error_message();
				continue;
			}

			$created_posts[ $platform ] = [
				'post_id'    => $post_id,
				'platform'   => $platform,
				'char_count' => mb_strlen( $refined ),
				'variants'   => $generated['variants'] ?? [],
			];

			// Save all variants as post meta for future editing
			update_post_meta( $post_id, 'content_variants', wp_json_encode( $generated['variants'] ) );
			update_post_meta( $post_id, 'research_data',    wp_json_encode( $research ) );
		}

		return [
			'topic'         => $topic,
			'account_type'  => $account_type,
			'scheduled_at'  => $scheduled_at,
			'created_posts' => $created_posts,
			'errors'        => $errors,
		];
	}
}
