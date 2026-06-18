<?php
/**
 * Social Media Scheduler Class
 *
 * Handles CPT registration, Telegram approval workflow,
 * Royal MCP publishing, and cron-based auto-publishing.
 *
 * @package Dernek
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dernek_Social_Scheduler {

	const WEBHOOK_SECRET    = '571632';
	const ROYAL_MCP_API_KEY = 'RIEN2eethmYMvthOOXpsormJxDj7cpWc';
	const ROYAL_MCP_API_URL = 'https://api.royalmcp.com/v1/publish';
	const APPS_SCRIPT_URL   = 'https://script.google.com/macros/s/AKfycbwRlqMSNbjsLaLkAdCZmFLvBIAnZAdeyegjbdWbkL3P-Pty2ruznNaampwqKKcyXYX6/exec';
	const CRON_HOOK         = 'dernek_publish_scheduled_posts';

	/** @var self|null */
	private static $instance = null;

	/** Get singleton instance. */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Constructor — wire up WordPress hooks. */
	private function __construct() {
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( self::CRON_HOOK, [ $this, 'run_scheduled_publishing' ] );
		add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'every_five_minutes', self::CRON_HOOK );
		}
	}

	// -------------------------------------------------------------------------
	// CPT Registration
	// -------------------------------------------------------------------------

	/**
	 * Register the social_post Custom Post Type and its meta fields.
	 */
	public function register_cpt(): void {
		$labels = [
			'name'               => __( 'Sosyal Medya Gönderileri', 'dernek' ),
			'singular_name'      => __( 'Sosyal Medya Gönderisi', 'dernek' ),
			'add_new'            => __( 'Yeni Gönderi Ekle', 'dernek' ),
			'add_new_item'       => __( 'Yeni Sosyal Gönderi Ekle', 'dernek' ),
			'edit_item'          => __( 'Gönderiyi Düzenle', 'dernek' ),
			'view_item'          => __( 'Gönderiyi Görüntüle', 'dernek' ),
			'search_items'       => __( 'Gönderilerde Ara', 'dernek' ),
			'not_found'          => __( 'Gönderi bulunamadı', 'dernek' ),
			'not_found_in_trash' => __( 'Çöp kutusunda gönderi yok', 'dernek' ),
		];

		register_post_type( 'social_post', [
			'labels'       => $labels,
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-share',
			'supports'     => [ 'title', 'editor', 'custom-fields' ],
			'capabilities' => [
				'edit_post'          => 'manage_options',
				'read_post'          => 'manage_options',
				'delete_post'        => 'manage_options',
				'edit_posts'         => 'manage_options',
				'edit_others_posts'  => 'manage_options',
				'publish_posts'      => 'manage_options',
				'read_private_posts' => 'manage_options',
			],
		] );

		$meta_fields = [
			'platform'            => 'string',
			'account_type'        => 'string',
			'content'             => 'string',
			'scheduled_at'        => 'string',
			'status'              => 'string',
			'telegram_message_id' => 'integer',
			'ai_tool_used'        => 'string',
			'publish_url'         => 'string',
			'failure_reason'      => 'string',
		];

		foreach ( $meta_fields as $key => $type ) {
			register_post_meta( 'social_post', $key, [
				'type'              => $type,
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'integer' === $type ? 'absint' : 'sanitize_text_field',
				'auth_callback'     => static function () {
					return current_user_can( 'manage_options' );
				},
			] );
		}
	}

	// -------------------------------------------------------------------------
	// Cron
	// -------------------------------------------------------------------------

	/**
	 * Register 5-minute cron interval.
	 */
	public function add_cron_interval( array $schedules ): array {
		$schedules['every_five_minutes'] = [
			'interval' => 300,
			'display'  => __( 'Her 5 Dakikada Bir', 'dernek' ),
		];
		return $schedules;
	}

	/**
	 * Cron callback — find approved posts whose scheduled_at <= now and publish them.
	 */
	public function run_scheduled_publishing(): void {
		$posts = get_posts( [
			'post_type'      => 'social_post',
			'posts_per_page' => 50,
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'   => 'status',
					'value' => 'approved',
				],
				[
					'key'     => 'scheduled_at',
					'value'   => current_time( 'c' ),
					'compare' => '<=',
					'type'    => 'DATETIME',
				],
			],
		] );

		foreach ( $posts as $post ) {
			$this->publish_post( $post->ID );
		}
	}

	// -------------------------------------------------------------------------
	// REST API Routes
	// -------------------------------------------------------------------------

	/**
	 * Register WordPress REST API routes.
	 */
	public function register_rest_routes(): void {
		// Telegram webhook — verified via WEBHOOK_SECRET header
		register_rest_route( 'dernek/v1', '/telegram-webhook', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_telegram_webhook' ],
			'permission_callback' => '__return_true',
		] );

		// Create social post
		register_rest_route( 'dernek/v1', '/social-post', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_create_social_post' ],
			'permission_callback' => [ $this, 'rest_auth_check' ],
			'args'                => $this->social_post_rest_args(),
		] );

		// Get single social post
		register_rest_route( 'dernek/v1', '/social-post/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_get_social_post' ],
			'permission_callback' => [ $this, 'rest_auth_check' ],
		] );

		// Update post status
		register_rest_route( 'dernek/v1', '/social-post/(?P<id>\d+)/status', [
			'methods'             => 'PATCH',
			'callback'            => [ $this, 'rest_update_post_status' ],
			'permission_callback' => [ $this, 'rest_auth_check' ],
		] );
	}

	/** REST permission check — manage_options capability. */
	public function rest_auth_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/** Argument schema for social post creation. */
	private function social_post_rest_args(): array {
		return [
			'title'        => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'content'      => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'platform'     => [
				'required' => true,
				'type'     => 'string',
				'enum'     => [ 'threads', 'facebook', 'instagram', 'twitter' ],
			],
			'account_type' => [
				'required' => true,
				'type'     => 'string',
				'enum'     => [ 'personal', 'dernek', 'viral' ],
			],
			'scheduled_at' => [
				'required' => false,
				'type'     => 'string',
			],
			'ai_tool_used' => [
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/** REST: Create a new social_post. */
	public function rest_create_social_post( WP_REST_Request $request ): WP_REST_Response {
		$data = [
			'title'        => $request->get_param( 'title' ),
			'content'      => $request->get_param( 'content' ),
			'platform'     => $request->get_param( 'platform' ),
			'account_type' => $request->get_param( 'account_type' ),
			'scheduled_at' => $request->get_param( 'scheduled_at' ) ?: wp_date( 'c', strtotime( '+1 hour' ) ),
			'ai_tool_used' => $request->get_param( 'ai_tool_used' ) ?: '',
		];

		$result = $this->schedule_post( $data );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 400 );
		}

		return new WP_REST_Response( [ 'post_id' => $result, 'status' => 'pending_approval' ], 201 );
	}

	/** REST: Get a single social_post. */
	public function rest_get_social_post( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'social_post' !== $post->post_type ) {
			return new WP_REST_Response( [ 'error' => 'Post not found' ], 404 );
		}

		return new WP_REST_Response( $this->format_post_response( $post ), 200 );
	}

	/** REST: Update post status. */
	public function rest_update_post_status( WP_REST_Request $request ): WP_REST_Response {
		$post_id    = (int) $request->get_param( 'id' );
		$new_status = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$valid      = [ 'draft', 'pending_approval', 'approved', 'published', 'failed' ];

		if ( ! in_array( $new_status, $valid, true ) ) {
			return new WP_REST_Response( [ 'error' => 'Geçersiz durum değeri' ], 400 );
		}

		update_post_meta( $post_id, 'status', $new_status );

		if ( 'approved' === $new_status ) {
			$scheduled_at = get_post_meta( $post_id, 'scheduled_at', true );
			if ( $scheduled_at && strtotime( $scheduled_at ) <= time() ) {
				$this->publish_post( $post_id );
			}
		}

		return new WP_REST_Response( [ 'post_id' => $post_id, 'status' => $new_status ], 200 );
	}

	// -------------------------------------------------------------------------
	// Core Methods
	// -------------------------------------------------------------------------

	/**
	 * Save a new social post and trigger Telegram approval.
	 *
	 * @param array{
	 *   title?: string,
	 *   content: string,
	 *   platform: string,
	 *   account_type: string,
	 *   scheduled_at?: string,
	 *   ai_tool_used?: string
	 * } $data
	 * @return int|\WP_Error Post ID on success.
	 */
	public function schedule_post( array $data ): int|WP_Error {
		$post_id = wp_insert_post( [
			'post_title'  => sanitize_text_field( $data['title'] ?? 'Sosyal Medya Gönderisi' ),
			'post_type'   => 'social_post',
			'post_status' => 'publish',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, 'platform',     sanitize_text_field( $data['platform'] ?? 'threads' ) );
		update_post_meta( $post_id, 'account_type', sanitize_text_field( $data['account_type'] ?? 'personal' ) );
		update_post_meta( $post_id, 'content',      sanitize_textarea_field( $data['content'] ?? '' ) );
		update_post_meta( $post_id, 'scheduled_at', sanitize_text_field( $data['scheduled_at'] ?? wp_date( 'c', strtotime( '+1 hour' ) ) ) );
		update_post_meta( $post_id, 'status',       'pending_approval' );
		update_post_meta( $post_id, 'ai_tool_used', sanitize_text_field( $data['ai_tool_used'] ?? '' ) );

		$this->send_telegram_approval( $post_id );

		return $post_id;
	}

	/**
	 * Publish a post via Royal MCP API, with direct-API fallback.
	 *
	 * @param int $post_id
	 * @return true|\WP_Error
	 */
	public function publish_post( int $post_id ): bool|WP_Error {
		$content      = get_post_meta( $post_id, 'content', true );
		$platform     = get_post_meta( $post_id, 'platform', true );
		$account_type = get_post_meta( $post_id, 'account_type', true );

		if ( empty( $content ) ) {
			$this->mark_failed( $post_id, 'İçerik boş' );
			return new WP_Error( 'empty_content', 'Post content is empty' );
		}

		$result = $this->publish_via_royal_mcp( $post_id, $content, $platform, $account_type );

		if ( is_wp_error( $result ) ) {
			$result = $this->publish_via_direct_api( $post_id, $content, $platform );
		}

		if ( is_wp_error( $result ) ) {
			$this->mark_failed( $post_id, $result->get_error_message() );
			return $result;
		}

		update_post_meta( $post_id, 'status',      'published' );
		update_post_meta( $post_id, 'publish_url', sanitize_url( $result['url'] ?? '' ) );

		$this->log_to_apps_script( $post_id, 'published', $result['url'] ?? '' );

		return true;
	}

	/**
	 * Publish via Royal MCP API.
	 *
	 * @return array|\WP_Error
	 */
	private function publish_via_royal_mcp( int $post_id, string $content, string $platform, string $account_type ): array|WP_Error {
		$response = wp_remote_post( self::ROYAL_MCP_API_URL, [
			'timeout' => 30,
			'headers' => [
				'Content-Type'        => 'application/json',
				'X-Royal-MCP-API-Key' => self::ROYAL_MCP_API_KEY,
			],
			'body' => wp_json_encode( [
				'post_id'      => $post_id,
				'content'      => $content,
				'platform'     => $platform,
				'account_type' => $account_type,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'royal_mcp_error', $body['message'] ?? "Royal MCP HTTP {$code}" );
		}

		return $body ?? [];
	}

	/**
	 * Direct platform API publishing (extend per platform as needed).
	 *
	 * @return array|\WP_Error
	 */
	private function publish_via_direct_api( int $post_id, string $content, string $platform ): array|WP_Error {
		// Placeholder — implement platform-specific OAuth flows here.
		return new WP_Error( 'no_direct_api', "Platform '{$platform}' için doğrudan API yapılandırılmamış." );
	}

	/** Mark a post as failed and log it. */
	private function mark_failed( int $post_id, string $reason ): void {
		update_post_meta( $post_id, 'status',         'failed' );
		update_post_meta( $post_id, 'failure_reason', sanitize_text_field( $reason ) );
		$this->log_to_apps_script( $post_id, 'failed', '' );
	}

	// -------------------------------------------------------------------------
	// Telegram
	// -------------------------------------------------------------------------

	/**
	 * Send a Telegram message with Approve / Reject / Edit inline keyboard.
	 *
	 * @param int $post_id
	 * @return array|\WP_Error
	 */
	public function send_telegram_approval( int $post_id ): array|WP_Error {
		$token   = get_option( 'dernek_telegram_token' );
		$chat_id = get_option( 'dernek_telegram_chat_id' );

		if ( empty( $token ) || empty( $chat_id ) ) {
			return new WP_Error( 'telegram_config', 'Telegram token veya chat_id tanımlı değil.' );
		}

		$content      = get_post_meta( $post_id, 'content', true );
		$platform     = get_post_meta( $post_id, 'platform', true );
		$account_type = get_post_meta( $post_id, 'account_type', true );
		$scheduled_at = get_post_meta( $post_id, 'scheduled_at', true );
		$ai_tool_used = get_post_meta( $post_id, 'ai_tool_used', true );

		$platform_icons  = [
			'threads'   => '🧵',
			'facebook'  => '📘',
			'instagram' => '📸',
			'twitter'   => '🐦',
		];
		$account_labels = [
			'personal' => '👤 Kişisel',
			'dernek'   => '🏛️ Dernek',
			'viral'    => '🔥 Viral',
		];

		$icon    = $platform_icons[ $platform ] ?? '📱';
		$account = $account_labels[ $account_type ] ?? $account_type;

		$message  = "🔔 *Onay Bekleyen Gönderi* \#{$post_id}\n\n";
		$message .= "{$icon} Platform: *" . ucfirst( $platform ) . "*\n";
		$message .= "👥 Hesap: *{$account}*\n";
		$message .= "⏰ Zamanlama: *{$scheduled_at}*\n";
		$message .= "🤖 Araç: *" . ( $ai_tool_used ?: 'Manuel' ) . "*\n\n";
		$message .= "📝 *İçerik:*\n```\n" . mb_substr( $content, 0, 800 ) . "\n```";

		$inline_keyboard = [
			'inline_keyboard' => [
				[
					[ 'text' => '✅ Onayla',   'callback_data' => "approve_{$post_id}" ],
					[ 'text' => '❌ Reddet',   'callback_data' => "reject_{$post_id}" ],
					[ 'text' => '✏️ Düzenle', 'callback_data' => "edit_{$post_id}" ],
				],
			],
		];

		$response = wp_remote_post( "https://api.telegram.org/bot{$token}/sendMessage", [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'chat_id'      => $chat_id,
				'text'         => $message,
				'parse_mode'   => 'Markdown',
				'reply_markup' => $inline_keyboard,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['result']['message_id'] ) ) {
			update_post_meta( $post_id, 'telegram_message_id', (int) $body['result']['message_id'] );
		}

		return $body ?? [];
	}

	/**
	 * REST callback: handle incoming Telegram webhook.
	 */
	public function rest_telegram_webhook( WP_REST_Request $request ): WP_REST_Response {
		$secret = $request->get_header( 'X-Telegram-Bot-Api-Secret-Token' );

		if ( $secret !== self::WEBHOOK_SECRET ) {
			return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
		}

		$body = $request->get_json_params();

		if ( isset( $body['callback_query'] ) ) {
			$this->handle_telegram_callback( $body['callback_query'] );
		}

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * Process Telegram inline keyboard button presses.
	 *
	 * @param array $callback_query Telegram callback_query object.
	 */
	public function handle_telegram_callback( array $callback_query ): void {
		$token       = get_option( 'dernek_telegram_token' );
		$callback_id = $callback_query['id'] ?? '';
		$data        = $callback_query['data'] ?? '';
		$message     = $callback_query['message'] ?? [];
		$chat_id     = $message['chat']['id'] ?? get_option( 'dernek_telegram_chat_id' );
		$message_id  = (int) ( $message['message_id'] ?? 0 );

		// Acknowledge callback to remove loading spinner
		if ( ! empty( $token ) && ! empty( $callback_id ) ) {
			wp_remote_post( "https://api.telegram.org/bot{$token}/answerCallbackQuery", [
				'timeout' => 10,
				'body'    => [ 'callback_query_id' => $callback_id ],
			] );
		}

		// Parse action and post_id from callback data: "approve_123"
		if ( ! preg_match( '/^(approve|reject|edit)_(\d+)$/', $data, $matches ) ) {
			return;
		}

		$action  = $matches[1];
		$post_id = (int) $matches[2];

		$post = get_post( $post_id );
		if ( ! $post || 'social_post' !== $post->post_type ) {
			$this->send_telegram_message( $chat_id, "❌ Gönderi \#{$post_id} bulunamadı." );
			return;
		}

		switch ( $action ) {
			case 'approve':
				update_post_meta( $post_id, 'status', 'approved' );
				$this->send_telegram_message( $chat_id, "✅ Gönderi \#{$post_id} onaylandı!" );

				$scheduled_at = get_post_meta( $post_id, 'scheduled_at', true );
				if ( $scheduled_at && strtotime( $scheduled_at ) <= time() ) {
					$this->publish_post( $post_id );
					$this->send_telegram_message( $chat_id, "🚀 Gönderi \#{$post_id} hemen yayınlandı!" );
				} else {
					$this->send_telegram_message( $chat_id, "⏰ Gönderi \#{$post_id} zamanlandı: {$scheduled_at}" );
				}
				break;

			case 'reject':
				update_post_meta( $post_id, 'status', 'draft' );
				$this->send_telegram_message( $chat_id, "❌ Gönderi \#{$post_id} reddedildi ve taslağa alındı." );
				break;

			case 'edit':
				$edit_url = admin_url( "post.php?post={$post_id}&action=edit" );
				$this->send_telegram_message( $chat_id, "✏️ Düzenlemek için:\n{$edit_url}" );
				break;
		}

		// Edit original Telegram message to show new status
		if ( ! empty( $token ) && $message_id > 0 ) {
			$status_labels = [
				'approve' => '✅ ONAYLANDI',
				'reject'  => '❌ REDDEDİLDİ',
				'edit'    => '✏️ DÜZENLEMEYE GÖNDERİLDİ',
			];

			$original_text = $message['text'] ?? '';
			$new_text      = $original_text . "\n\n" . ( $status_labels[ $action ] ?? '' );

			wp_remote_post( "https://api.telegram.org/bot{$token}/editMessageText", [
				'timeout' => 10,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'chat_id'    => $chat_id,
					'message_id' => $message_id,
					'text'       => mb_substr( $new_text, 0, 4096 ),
					'parse_mode' => 'Markdown',
				] ),
			] );
		}
	}

	/**
	 * Send a plain text Telegram message.
	 *
	 * @param string|int $chat_id
	 * @param string     $text
	 */
	private function send_telegram_message( $chat_id, string $text ): void {
		$token = get_option( 'dernek_telegram_token' );
		if ( empty( $token ) ) {
			return;
		}

		wp_remote_post( "https://api.telegram.org/bot{$token}/sendMessage", [
			'timeout' => 10,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'chat_id'    => $chat_id,
				'text'       => $text,
				'parse_mode' => 'Markdown',
			] ),
		] );
	}

	// -------------------------------------------------------------------------
	// Apps Script Logging
	// -------------------------------------------------------------------------

	/**
	 * Log a status update to Google Sheets via Apps Script.
	 */
	private function log_to_apps_script( int $post_id, string $status, string $publish_url ): void {
		$platform     = get_post_meta( $post_id, 'platform', true );
		$account_type = get_post_meta( $post_id, 'account_type', true );
		$content      = get_post_meta( $post_id, 'content', true );
		$ai_tool_used = get_post_meta( $post_id, 'ai_tool_used', true );

		wp_remote_post( self::APPS_SCRIPT_URL, [
			'timeout'   => 15,
			'headers'   => [ 'Content-Type' => 'application/json' ],
			'body'      => wp_json_encode( [
				'action'      => 'updatePostStatus',
				'post_id'     => $post_id,
				'date'        => wp_date( 'Y-m-d H:i:s' ),
				'account'     => $account_type,
				'platform'    => $platform,
				'summary'     => mb_substr( $content, 0, 100 ),
				'status'      => $status,
				'publish_url' => $publish_url,
				'tool'        => $ai_tool_used,
			] ),
		] );
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/** Format a social_post WP_Post for API responses. */
	private function format_post_response( WP_Post $post ): array {
		return [
			'id'                  => $post->ID,
			'title'               => $post->post_title,
			'platform'            => get_post_meta( $post->ID, 'platform', true ),
			'account_type'        => get_post_meta( $post->ID, 'account_type', true ),
			'content'             => get_post_meta( $post->ID, 'content', true ),
			'scheduled_at'        => get_post_meta( $post->ID, 'scheduled_at', true ),
			'status'              => get_post_meta( $post->ID, 'status', true ),
			'telegram_message_id' => (int) get_post_meta( $post->ID, 'telegram_message_id', true ),
			'ai_tool_used'        => get_post_meta( $post->ID, 'ai_tool_used', true ),
			'publish_url'         => get_post_meta( $post->ID, 'publish_url', true ),
			'failure_reason'      => get_post_meta( $post->ID, 'failure_reason', true ),
		];
	}
}
