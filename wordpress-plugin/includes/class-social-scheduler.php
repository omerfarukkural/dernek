<?php
/**
 * Dernek Social Scheduler — Tüm Platformlar
 * Threads, Facebook, Instagram, Twitter/X, LinkedIn, TikTok, YouTube, Pinterest, Bluesky
 * JeSuspended bağımlılığı yok
 */
if (!defined('ABSPATH')) exit;

class Dernek_Social_Scheduler {

    const CPT = 'social_post';

    const PLATFORMS = [
        'threads'   => ['name' => 'Threads',    'limit' => 500,    'emoji' => '🧵'],
        'facebook'  => ['name' => 'Facebook',   'limit' => 63206,  'emoji' => '📘'],
        'instagram' => ['name' => 'Instagram',  'limit' => 2200,   'emoji' => '📸'],
        'twitter'   => ['name' => 'Twitter/X',  'limit' => 280,    'emoji' => '🐦'],
        'linkedin'  => ['name' => 'LinkedIn',   'limit' => 3000,   'emoji' => '💼'],
        'tiktok'    => ['name' => 'TikTok',     'limit' => 2200,   'emoji' => '🎵'],
        'youtube'   => ['name' => 'YouTube',    'limit' => 5000,   'emoji' => '▶️'],
        'pinterest' => ['name' => 'Pinterest',  'limit' => 500,    'emoji' => '📌'],
        'bluesky'   => ['name' => 'Bluesky',    'limit' => 300,    'emoji' => '☁️'],
    ];

    public static function register_cpt() {
        register_post_type(self::CPT, [
            'labels'          => [
                'name'          => 'Sosyal Medya Gönderileri',
                'singular_name' => 'Gönderi',
                'add_new_item'  => 'Yeni Gönderi',
                'edit_item'     => 'Gönderiyi Düzenle',
            ],
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => true,
            'show_in_rest'    => true,
            'supports'        => ['title', 'editor', 'custom-fields'],
            'menu_icon'       => 'dashicons-share',
            'capability_type' => 'post',
        ]);

        $metas = [
            'platform'            => 'string',
            'account_type'        => 'string',
            'scheduled_at'        => 'string',
            'post_status_dernek'  => 'string',
            'telegram_message_id' => 'integer',
            'ai_tool_used'        => 'string',
            'publish_url'         => 'string',
            'failure_reason'      => 'string',
            'platform_post_id'    => 'string',
            'media_url'           => 'string',
        ];
        foreach ($metas as $key => $type) {
            register_post_meta(self::CPT, $key, ['type' => $type, 'single' => true, 'show_in_rest' => true]);
        }
    }

    public static function register_routes() {
        $auth = ['Dernek_REST_API', 'auth'];

        register_rest_route('dernek/v1', '/social-post', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'api_create'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('dernek/v1', '/social-post/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [self::class, 'api_update'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('dernek/v1', '/social-posts', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'api_list'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('dernek/v1', '/platforms', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'api_platforms'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('dernek/v1', '/telegram-webhook', [
            'methods'             => ['POST', 'GET'],
            'callback'            => [self::class, 'telegram_webhook'],
            'permission_callback' => [self::class, 'verify_telegram_secret'],
        ]);
    }

    // ─── Singleton ───────────────────────────────────────────────────────────

    /** @var self|null */
    private static $instance = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Create a social post CPT entry and send Telegram approval.
     * Accepts a plain array instead of a WP_REST_Request.
     */
    public function schedule_post(array $data): int|\WP_Error {
        if (empty($data['content'])) {
            return new \WP_Error('missing_content', 'İçerik gerekli');
        }

        $platform     = sanitize_text_field($data['platform']     ?? 'threads');
        $account_type = sanitize_text_field($data['account_type'] ?? 'personal');
        $limit        = self::PLATFORMS[$platform]['limit'] ?? 500;
        $content      = mb_substr(wp_kses_post($data['content']), 0, $limit);

        // Normalize scheduled_at to MySQL DATETIME
        $raw_scheduled = $data['scheduled_at'] ?? '';
        $scheduled_at  = $raw_scheduled ? date('Y-m-d H:i:s', strtotime($raw_scheduled)) : '';

        $post_id = wp_insert_post([
            'post_type'    => self::CPT,
            'post_title'   => sanitize_text_field($data['title'] ?? mb_substr(strip_tags($content), 0, 80)),
            'post_content' => $content,
            'post_status'  => 'publish',
        ]);

        if (is_wp_error($post_id)) return $post_id;

        update_post_meta($post_id, 'platform',           $platform);
        update_post_meta($post_id, 'account_type',       $account_type);
        update_post_meta($post_id, 'scheduled_at',       $scheduled_at);
        update_post_meta($post_id, 'post_status_dernek', 'pending_approval');
        update_post_meta($post_id, 'ai_tool_used',       sanitize_text_field($data['ai_tool_used'] ?? 'pipeline'));

        self::send_telegram_approval($post_id, $content);
        self::log_to_sheets($post_id, $data, 'pending_approval');

        return $post_id;
    }

    /** Verify X-Telegram-Bot-Api-Secret-Token header for webhook authenticity. */
    public static function verify_telegram_secret(\WP_REST_Request $r): bool {
        $secret = get_option('dernek_api_secret', '');
        if (empty($secret)) return false;
        $header = $r->get_header('X-Telegram-Bot-Api-Secret-Token');
        return !empty($header) && hash_equals($secret, $header);
    }

    // ─── REST handlers ───────────────────────────────────────────────────────

    public static function api_platforms() {
        return rest_ensure_response(self::PLATFORMS);
    }

    public static function api_create(\WP_REST_Request $request) {
        $d = $request->get_json_params();
        if (empty($d['content'])) {
            return new \WP_Error('missing_content', 'İçerik gerekli', ['status' => 400]);
        }

        $platform     = sanitize_text_field($d['platform']     ?? 'threads');
        $account_type = sanitize_text_field($d['account_type'] ?? 'personal');

        $limit   = self::PLATFORMS[$platform]['limit'] ?? 500;
        $content = mb_substr(wp_kses_post($d['content']), 0, $limit);

        $post_id = wp_insert_post([
            'post_type'    => self::CPT,
            'post_title'   => mb_substr(strip_tags($content), 0, 80),
            'post_content' => $content,
            'post_status'  => 'publish',
        ]);

        if (is_wp_error($post_id)) return $post_id;

        update_post_meta($post_id, 'platform',           $platform);
        update_post_meta($post_id, 'account_type',       $account_type);
        update_post_meta($post_id, 'scheduled_at',       sanitize_text_field($d['scheduled_at'] ?? ''));
        update_post_meta($post_id, 'post_status_dernek', 'pending_approval');
        update_post_meta($post_id, 'ai_tool_used',       sanitize_text_field($d['ai_tool'] ?? 'pipeline'));
        if (!empty($d['media_url'])) {
            update_post_meta($post_id, 'media_url', esc_url_raw($d['media_url']));
        }

        $tg = self::send_telegram_approval($post_id, $content);
        self::log_to_sheets($post_id, $d, 'pending_approval');

        return rest_ensure_response([
            'success'       => true,
            'post_id'       => $post_id,
            'status'        => 'pending_approval',
            'telegram_sent' => !is_wp_error($tg),
            'char_limit'    => $limit,
            'char_used'     => mb_strlen($content),
        ]);
    }

    public static function api_update(\WP_REST_Request $request) {
        $post_id = (int) $request->get_param('id');
        $d       = $request->get_json_params();

        if (!get_post($post_id)) {
            return new \WP_Error('not_found', 'Post bulunamadı', ['status' => 404]);
        }

        if (!empty($d['status'])) {
            $status = sanitize_text_field($d['status']);
            update_post_meta($post_id, 'post_status_dernek', $status);

            if ($status === 'approved') {
                $scheduled = get_post_meta($post_id, 'scheduled_at', true);
                if (!$scheduled || strtotime($scheduled) <= time()) {
                    self::publish_post($post_id);
                }
            }
        }
        if (!empty($d['publish_url'])) update_post_meta($post_id, 'publish_url', esc_url_raw($d['publish_url']));
        if (!empty($d['media_url']))   update_post_meta($post_id, 'media_url', esc_url_raw($d['media_url']));

        return rest_ensure_response(['success' => true, 'post_id' => $post_id]);
    }

    public static function api_list(\WP_REST_Request $request) {
        $status   = sanitize_text_field($request->get_param('status')   ?? 'pending_approval');
        $platform = sanitize_text_field($request->get_param('platform') ?? '');

        $meta_query = [['key' => 'post_status_dernek', 'value' => $status]];
        if ($platform) $meta_query[] = ['key' => 'platform', 'value' => $platform];

        $query = new \WP_Query([
            'post_type'      => self::CPT,
            'posts_per_page' => 50,
            'meta_query'     => $meta_query,
        ]);

        $posts = [];
        foreach ($query->posts as $p) {
            $pl    = get_post_meta($p->ID, 'platform', true);
            $posts[] = [
                'id'            => $p->ID,
                'content'       => $p->post_content,
                'platform'      => $pl,
                'platform_name' => self::PLATFORMS[$pl]['name'] ?? $pl,
                'account_type'  => get_post_meta($p->ID, 'account_type', true),
                'scheduled_at'  => get_post_meta($p->ID, 'scheduled_at', true),
                'status'        => get_post_meta($p->ID, 'post_status_dernek', true),
                'ai_tool'       => get_post_meta($p->ID, 'ai_tool_used', true),
                'publish_url'   => get_post_meta($p->ID, 'publish_url', true),
                'media_url'     => get_post_meta($p->ID, 'media_url', true),
                'created'       => get_the_date('c', $p->ID),
            ];
        }

        return rest_ensure_response(['posts' => $posts, 'total' => $query->found_posts]);
    }

    // ─── Telegram ─────────────────────────────────────────────────────────────

    public static function send_telegram_approval($post_id, $content) {
        $token   = get_option('dernek_telegram_token', '');
        $chat_id = get_option('dernek_telegram_chat_id', '6557940186');

        if (!$token) return new \WP_Error('no_token', 'Telegram token eksik');

        $platform     = get_post_meta($post_id, 'platform', true);
        $account_type = get_post_meta($post_id, 'account_type', true);
        $scheduled_at = get_post_meta($post_id, 'scheduled_at', true);
        $media_url    = get_post_meta($post_id, 'media_url', true);

        $pl_conf = self::PLATFORMS[$platform] ?? ['name' => $platform, 'emoji' => '📱'];
        $labels  = ['personal' => '👤 Kişisel', 'dernek' => '🏢 Dernek', 'viral' => '🔥 Viral'];

        $text  = "{$pl_conf['emoji']} *{$pl_conf['name']} Gönderi Onayı*\n\n";
        $text .= "👥 Hesap: *" . ($labels[$account_type] ?? $account_type) . "*\n";
        $text .= "📊 Karakter: " . mb_strlen($content) . "/" . ($pl_conf['limit'] ?? '?') . "\n";
        if ($scheduled_at) $text .= "⏰ Plan: *$scheduled_at*\n";
        if ($media_url) $text .= "🖼 Medya: $media_url\n";
        $text .= "\n📝 *İçerik:*\n" . mb_substr(strip_tags($content), 0, 700);
        if (mb_strlen($content) > 700) $text .= "…";
        $text .= "\n\n🆔 `post:$post_id`";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Yayınla', 'callback_data' => "approve_$post_id"],
                    ['text' => '❌ Reddet',  'callback_data' => "reject_$post_id"],
                ],
                [
                    ['text' => '⏰ +1 Saat',  'callback_data' => "delay1h_$post_id"],
                    ['text' => '⏰ +6 Saat',  'callback_data' => "delay6h_$post_id"],
                    ['text' => '⏰ +1 Gün',   'callback_data' => "delay1d_$post_id"],
                ],
                [
                    ['text' => '✏️ WP Düzenle', 'callback_data' => "editlink_$post_id"],
                    ['text' => '📋 Tüm Taslaklar', 'callback_data' => "list_pending"],
                ],
            ],
        ];

        $resp = wp_remote_post("https://api.telegram.org/bot$token/sendMessage", [
            'body'    => json_encode([
                'chat_id'      => $chat_id,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => $keyboard,
            ]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 15,
        ]);

        if (is_wp_error($resp)) return $resp;
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($body['result']['message_id'])) {
            update_post_meta($post_id, 'telegram_message_id', $body['result']['message_id']);
        }
        return $body;
    }

    public static function telegram_webhook(\WP_REST_Request $request) {
        $body = $request->get_json_params();
        if (empty($body['callback_query'])) return rest_ensure_response(['ok' => true]);

        $cb    = $body['callback_query'];
        $data  = $cb['data'] ?? '';
        $token = get_option('dernek_telegram_token', '');
        $chat  = $cb['message']['chat']['id'] ?? null;
        $msg   = $cb['message']['message_id'] ?? null;

        // Spinner'ı kapat
        if ($token) {
            wp_remote_post("https://api.telegram.org/bot$token/answerCallbackQuery", [
                'body'    => json_encode(['callback_query_id' => $cb['id']]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 5,
            ]);
        }

        if ($data === 'list_pending') {
            $items = get_posts([
                'post_type'      => self::CPT,
                'posts_per_page' => 10,
                'meta_query'     => [['key' => 'post_status_dernek', 'value' => 'pending_approval']],
            ]);
            $list = count($items) === 0 ? 'Bekleyen gönderi yok.' :
                implode("\n", array_map(fn($p) =>
                    "• [#{$p->ID}] " . get_post_meta($p->ID, 'platform', true) . ": " .
                    mb_substr(strip_tags($p->post_content), 0, 60) . "…",
                    $items
                ));
            self::tg_send($token, $chat, "📋 *Bekleyen Gönderiler:*\n$list", null);
            return rest_ensure_response(['ok' => true]);
        }

        if (!preg_match('/^(approve|reject|delay1h|delay6h|delay1d|editlink)_(\d+)$/', $data, $m)) {
            return rest_ensure_response(['ok' => true]);
        }

        [, $action, $post_id] = $m;
        $post_id = (int) $post_id;

        switch ($action) {
            case 'approve':
                update_post_meta($post_id, 'post_status_dernek', 'approved');
                $scheduled = get_post_meta($post_id, 'scheduled_at', true);
                if (!$scheduled || strtotime($scheduled) <= time()) {
                    $result = self::publish_post($post_id);
                    $reply  = is_wp_error($result)
                        ? "⚠️ Onaylandı fakat yayın hatası:\n" . $result->get_error_message()
                        : "✅ Post #{$post_id} yayınlandı!";
                } else {
                    $reply = "✅ Post #{$post_id} onaylandı.\n📅 $scheduled tarihinde yayınlanacak.";
                }
                break;

            case 'reject':
                update_post_meta($post_id, 'post_status_dernek', 'rejected');
                $reply = "❌ Post #{$post_id} reddedildi.";
                break;

            case 'delay1h':
                $new = date('Y-m-d H:i:s', time() + 3600);
                update_post_meta($post_id, 'scheduled_at', $new);
                update_post_meta($post_id, 'post_status_dernek', 'approved');
                $reply = "⏰ Post #{$post_id} 1 saat ertelendi: $new";
                break;

            case 'delay6h':
                $new = date('Y-m-d H:i:s', time() + 21600);
                update_post_meta($post_id, 'scheduled_at', $new);
                update_post_meta($post_id, 'post_status_dernek', 'approved');
                $reply = "⏰ Post #{$post_id} 6 saat ertelendi: $new";
                break;

            case 'delay1d':
                $new = date('Y-m-d H:i:s', time() + 86400);
                update_post_meta($post_id, 'scheduled_at', $new);
                update_post_meta($post_id, 'post_status_dernek', 'approved');
                $reply = "⏰ Post #{$post_id} 1 gün ertelendi: $new";
                break;

            case 'editlink':
                $url   = admin_url("post.php?post=$post_id&action=edit");
                $reply = "✏️ Düzenleme linki:\n$url";
                break;

            default:
                $reply = '';
        }

        self::tg_send($token, $chat, $reply, $msg);
        self::log_to_sheets($post_id, [], get_post_meta($post_id, 'post_status_dernek', true));
        return rest_ensure_response(['ok' => true]);
    }

    private static function tg_send($token, $chat_id, $text, $original_msg_id = null) {
        if (!$token || !$chat_id || !$text) return;

        if ($original_msg_id) {
            wp_remote_post("https://api.telegram.org/bot$token/editMessageReplyMarkup", [
                'body'    => json_encode(['chat_id' => $chat_id, 'message_id' => $original_msg_id, 'reply_markup' => ['inline_keyboard' => []]]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 5,
            ]);
        }

        wp_remote_post("https://api.telegram.org/bot$token/sendMessage", [
            'body'    => json_encode(['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'Markdown']),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10,
        ]);
    }

    // ─── Publish router ──────────────────────────────────────────────────────

    public static function publish_post($post_id) {
        $content      = get_post_field('post_content', $post_id);
        $platform     = get_post_meta($post_id, 'platform', true);
        $account_type = get_post_meta($post_id, 'account_type', true);
        $media_url    = get_post_meta($post_id, 'media_url', true);

        update_post_meta($post_id, 'post_status_dernek', 'publishing');

        $method = 'publish_' . $platform;
        if (method_exists(self::class, $method)) {
            $result = self::$method($post_id, $content, $account_type, $media_url);
        } else {
            $result = new \WP_Error('unsupported', "Platform henüz desteklenmiyor: $platform");
        }

        if (is_wp_error($result)) {
            update_post_meta($post_id, 'post_status_dernek', 'failed');
            update_post_meta($post_id, 'failure_reason', $result->get_error_message());
        } else {
            update_post_meta($post_id, 'post_status_dernek', 'published');
            if (!empty($result['url'])) update_post_meta($post_id, 'publish_url', $result['url']);
            if (!empty($result['id']))  update_post_meta($post_id, 'platform_post_id', $result['id']);
            self::log_to_sheets($post_id, [], 'published');
        }

        return $result;
    }

    private static function get_account_config($account_type, $platform) {
        $accounts = json_decode(get_option('dernek_social_accounts', '{}'), true);
        return $accounts[$account_type][$platform] ?? null;
    }

    // ─── Threads (Meta Graph API) ─────────────────────────────────────────────

    private static function publish_threads($post_id, $content, $account_type, $media_url = '') {
        $conf = self::get_account_config($account_type, 'threads');
        if (!$conf) return new \WP_Error('no_account', "Threads hesabı tanımlı değil: $account_type");

        $user_id = $conf['user_id'] ?? '';
        $token   = $conf['access_token'] ?? '';
        if (!$user_id || !$token) return new \WP_Error('no_creds', 'Threads user_id veya access_token eksik');

        $body = ['media_type' => 'TEXT', 'text' => mb_substr($content, 0, 500), 'access_token' => $token];
        if ($media_url) { $body['media_type'] = 'IMAGE'; $body['image_url'] = $media_url; }

        $container = self::http_post("https://graph.threads.net/v1.0/$user_id/threads", $body);
        if (is_wp_error($container)) return $container;
        if (empty($container['id'])) return new \WP_Error('threads_container', $container['error']['message'] ?? 'Container hatası');

        sleep(2);

        $pub = self::http_post("https://graph.threads.net/v1.0/$user_id/threads_publish", [
            'creation_id' => $container['id'], 'access_token' => $token,
        ]);
        if (is_wp_error($pub)) return $pub;
        if (empty($pub['id'])) return new \WP_Error('threads_pub', $pub['error']['message'] ?? 'Yayın hatası');

        return ['id' => $pub['id'], 'url' => "https://www.threads.net/@$user_id"];
    }

    // ─── Facebook (Graph API) ─────────────────────────────────────────────────

    private static function publish_facebook($post_id, $content, $account_type, $media_url = '') {
        $conf = self::get_account_config($account_type, 'facebook');
        if (!$conf) return new \WP_Error('no_account', "Facebook hesabı tanımlı değil: $account_type");

        $page_id = $conf['page_id']     ?? '';
        $token   = $conf['access_token'] ?? '';
        if (!$page_id || !$token) return new \WP_Error('no_creds', 'Facebook page_id veya access_token eksik');

        $body = ['message' => mb_substr($content, 0, 63206), 'access_token' => $token];
        if ($media_url) $body['link'] = $media_url;

        $resp = self::http_post("https://graph.facebook.com/v21.0/$page_id/feed", $body);
        if (is_wp_error($resp)) return $resp;
        if (empty($resp['id'])) return new \WP_Error('fb_fail', $resp['error']['message'] ?? 'FB yayın hatası');

        return ['id' => $resp['id'], 'url' => "https://www.facebook.com/$page_id"];
    }

    // ─── Instagram (Graph API) ────────────────────────────────────────────────

    private static function publish_instagram($post_id, $content, $account_type, $media_url = '') {
        $conf = self::get_account_config($account_type, 'instagram');
        if (!$conf) return new \WP_Error('no_account', "Instagram hesabı tanımlı değil: $account_type");

        $ig_id = $conf['user_id']      ?? '';
        $token = $conf['access_token'] ?? '';
        if (!$ig_id || !$token) return new \WP_Error('no_creds', 'Instagram user_id veya access_token eksik');

        $media_body = ['caption' => mb_substr($content, 0, 2200), 'access_token' => $token];
        if ($media_url) {
            $media_body['image_url']  = $media_url;
            $media_body['media_type'] = 'IMAGE';
        } else {
            // Medyasız text post için carousel container
            return new \WP_Error('ig_no_media', 'Instagram için medya URL\'si gerekli');
        }

        $container = self::http_post("https://graph.facebook.com/v21.0/$ig_id/media", $media_body);
        if (is_wp_error($container)) return $container;
        if (empty($container['id'])) return new \WP_Error('ig_container', $container['error']['message'] ?? 'Container hatası');

        sleep(3);

        $pub = self::http_post("https://graph.facebook.com/v21.0/$ig_id/media_publish", [
            'creation_id' => $container['id'], 'access_token' => $token,
        ]);
        if (is_wp_error($pub)) return $pub;
        if (empty($pub['id'])) return new \WP_Error('ig_pub', $pub['error']['message'] ?? 'IG yayın hatası');

        return ['id' => $pub['id'], 'url' => "https://www.instagram.com/"];
    }

    // ─── Twitter/X (API v2) ───────────────────────────────────────────────────

    private static function publish_twitter($post_id, $content, $account_type, $media_url = '') {
        $conf = self::get_account_config($account_type, 'twitter');
        if (!$conf) return new \WP_Error('no_account', "Twitter hesabı tanımlı değil: $account_type");

        $bearer   = $conf['bearer_token']       ?? '';
        $api_key  = $conf['api_key']             ?? '';
        $api_sec  = $conf['api_secret']          ?? '';
        $acc_tok  = $conf['access_token']        ?? '';
        $acc_sec  = $conf['access_token_secret'] ?? '';

        if (!$api_key || !$api_sec || !$acc_tok || !$acc_sec) {
            return new \WP_Error('no_creds', 'Twitter API keys eksik');
        }

        $tweet_text = mb_substr($content, 0, 280);
        $body       = ['text' => $tweet_text];

        // OAuth 1.0a imzası
        $auth = self::twitter_oauth1_header('POST', 'https://api.twitter.com/2/tweets', $api_key, $api_sec, $acc_tok, $acc_sec);

        $resp = wp_remote_post('https://api.twitter.com/2/tweets', [
            'body'    => json_encode($body),
            'headers' => [
                'Authorization' => $auth,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($resp)) return $resp;
        $rb = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($rb['data']['id'])) return new \WP_Error('twitter_fail', $rb['detail'] ?? $rb['errors'][0]['message'] ?? 'Tweet hatası');

        return ['id' => $rb['data']['id'], 'url' => "https://twitter.com/i/web/status/{$rb['data']['id']}"];
    }

    private static function twitter_oauth1_header($method, $url, $key, $secret, $token, $token_secret) {
        $nonce     = bin2hex(random_bytes(16));
        $timestamp = time();
        $params    = [
            'oauth_consumer_key'     => $key,
            'oauth_nonce'            => $nonce,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => $timestamp,
            'oauth_token'            => $token,
            'oauth_version'          => '1.0',
        ];
        ksort($params);
        $base = $method . '&' . rawurlencode($url) . '&' . rawurlencode(http_build_query($params));
        $sig  = base64_encode(hash_hmac('sha1', $base, rawurlencode($secret) . '&' . rawurlencode($token_secret), true));
        $params['oauth_signature'] = $sig;

        $parts = [];
        foreach ($params as $k => $v) $parts[] = rawurlencode($k) . '="' . rawurlencode($v) . '"';
        return 'OAuth ' . implode(', ', $parts);
    }

    // ─── LinkedIn (API v2) ────────────────────────────────────────────────────

    private static function publish_linkedin($post_id, $content, $account_type, $media_url = '') {
        $conf = self::get_account_config($account_type, 'linkedin');
        if (!$conf) return new \WP_Error('no_account', "LinkedIn hesabı tanımlı değil: $account_type");

        $person_urn = $conf['person_urn']  ?? '';  // urn:li:person:XXXXX
        $token      = $conf['access_token'] ?? '';
        if (!$person_urn || !$token) return new \WP_Error('no_creds', 'LinkedIn person_urn veya access_token eksik');

        $body = [
            'author'         => $person_urn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary'  => ['text' => mb_substr($content, 0, 3000)],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
        ];

        $resp = wp_remote_post('https://api.linkedin.com/v2/ugcPosts', [
            'body'    => json_encode($body),
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type'  => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $rb   = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code >= 400) return new \WP_Error('linkedin_fail', $rb['message'] ?? "HTTP $code");

        $post_urn = $rb['id'] ?? '';
        return ['id' => $post_urn, 'url' => 'https://www.linkedin.com/feed/'];
    }

    // ─── Bluesky (AT Protocol) ───────────────────────────────────────────────

    private static function publish_bluesky($post_id, $content, $account_type, $media_url = '') {
        $conf = self::get_account_config($account_type, 'bluesky');
        if (!$conf) return new \WP_Error('no_account', "Bluesky hesabı tanımlı değil: $account_type");

        $handle   = $conf['handle']   ?? '';  // örn: kullanici.bsky.social
        $password = $conf['password'] ?? '';  // App Password
        if (!$handle || !$password) return new \WP_Error('no_creds', 'Bluesky handle veya password eksik');

        // Oturum aç
        $session = self::http_post_json('https://bsky.social/xrpc/com.atproto.server.createSession', [
            'identifier' => $handle,
            'password'   => $password,
        ]);
        if (is_wp_error($session)) return $session;
        if (empty($session['accessJwt'])) return new \WP_Error('bsky_auth', 'Bluesky oturum açılamadı');

        $jwt = $session['accessJwt'];
        $did = $session['did'];

        $record = [
            '$type'     => 'app.bsky.feed.post',
            'text'      => mb_substr($content, 0, 300),
            'createdAt' => gmdate('c'),
            'langs'     => ['tr'],
        ];

        $resp = wp_remote_post('https://bsky.social/xrpc/com.atproto.repo.createRecord', [
            'body'    => json_encode([
                'repo'       => $did,
                'collection' => 'app.bsky.feed.post',
                'record'     => $record,
            ]),
            'headers' => [
                'Authorization' => "Bearer $jwt",
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($resp)) return $resp;
        $rb = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($rb['uri'])) return new \WP_Error('bsky_fail', $rb['error'] ?? 'Bluesky yayın hatası');

        return ['id' => $rb['uri'], 'url' => "https://bsky.app/profile/$handle"];
    }

    // ─── Pinterest (API v5) ───────────────────────────────────────────────────

    private static function publish_pinterest($post_id, $content, $account_type, $media_url = '') {
        $conf = self::get_account_config($account_type, 'pinterest');
        if (!$conf) return new \WP_Error('no_account', "Pinterest hesabı tanımlı değil: $account_type");

        $token   = $conf['access_token'] ?? '';
        $board   = $conf['board_id']     ?? '';
        if (!$token) return new \WP_Error('no_creds', 'Pinterest access_token eksik');

        $body = [
            'title'       => mb_substr(strip_tags($content), 0, 100),
            'description' => mb_substr($content, 0, 500),
            'board_id'    => $board,
            'media_source' => $media_url
                ? ['source_type' => 'image_url', 'url' => $media_url]
                : ['source_type' => 'image_base64', 'content_type' => 'image/png', 'data' => ''],
        ];

        $resp = wp_remote_post('https://api.pinterest.com/v5/pins', [
            'body'    => json_encode($body),
            'headers' => ['Authorization' => "Bearer $token", 'Content-Type' => 'application/json'],
            'timeout' => 20,
        ]);

        if (is_wp_error($resp)) return $resp;
        $rb   = json_decode(wp_remote_retrieve_body($resp), true);
        $code = wp_remote_retrieve_response_code($resp);
        if ($code >= 400) return new \WP_Error('pinterest_fail', $rb['message'] ?? "HTTP $code");

        return ['id' => $rb['id'] ?? '', 'url' => "https://www.pinterest.com/pin/{$rb['id']}/"];
    }

    // ─── TikTok (Content Posting API) ────────────────────────────────────────

    private static function publish_tiktok($post_id, $content, $account_type, $media_url = '') {
        $conf = self::get_account_config($account_type, 'tiktok');
        if (!$conf) return new \WP_Error('no_account', "TikTok hesabı tanımlı değil: $account_type");

        $token = $conf['access_token'] ?? '';
        if (!$token) return new \WP_Error('no_creds', 'TikTok access_token eksik');

        if (!$media_url) return new \WP_Error('tiktok_no_media', 'TikTok için video URL\'si gerekli');

        // TikTok Content Posting API — video upload
        $resp = wp_remote_post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
            'body'    => json_encode([
                'post_info' => [
                    'title'        => mb_substr($content, 0, 150),
                    'privacy_level' => 'PUBLIC_TO_EVERYONE',
                    'disable_duet'   => false,
                    'disable_stitch' => false,
                    'disable_comment'=> false,
                ],
                'source_info' => [
                    'source'    => 'PULL_FROM_URL',
                    'video_url' => $media_url,
                ],
            ]),
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type'  => 'application/json; charset=UTF-8',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($resp)) return $resp;
        $rb   = json_decode(wp_remote_retrieve_body($resp), true);
        $code = wp_remote_retrieve_response_code($resp);
        if ($code >= 400) return new \WP_Error('tiktok_fail', $rb['error']['message'] ?? "HTTP $code");

        return ['id' => $rb['data']['publish_id'] ?? '', 'url' => 'https://www.tiktok.com/'];
    }

    // ─── YouTube (Data API v3) ────────────────────────────────────────────────

    private static function publish_youtube($post_id, $content, $account_type, $media_url = '') {
        $conf = self::get_account_config($account_type, 'youtube');
        if (!$conf) return new \WP_Error('no_account', "YouTube hesabı tanımlı değil: $account_type");

        $token = $conf['access_token'] ?? '';
        if (!$token) return new \WP_Error('no_creds', 'YouTube access_token eksik');

        // YouTube Community Post (yeni API - sadece channel post)
        // Not: Video upload için ayrı upload endpoint gerekli
        $channel_id = $conf['channel_id'] ?? '';

        // Community post için YouTube Data API
        $resp = wp_remote_post('https://www.googleapis.com/youtube/v3/communityPosts?part=snippet', [
            'body'    => json_encode([
                'snippet' => [
                    'type'       => 'textOriginal',
                    'textOriginal' => ['text' => mb_substr($content, 0, 5000)],
                ],
            ]),
            'headers' => ['Authorization' => "Bearer $token", 'Content-Type' => 'application/json'],
            'timeout' => 20,
        ]);

        if (is_wp_error($resp)) return $resp;
        $rb   = json_decode(wp_remote_retrieve_body($resp), true);
        $code = wp_remote_retrieve_response_code($resp);
        if ($code >= 400) return new \WP_Error('youtube_fail', $rb['error']['message'] ?? "HTTP $code");

        return ['id' => $rb['id'] ?? '', 'url' => "https://www.youtube.com/channel/$channel_id/community"];
    }

    // ─── HTTP yardımcıları ────────────────────────────────────────────────────

    private static function http_post($url, $body) {
        $resp = wp_remote_post($url, [
            'body'    => is_array($body) ? $body : json_encode($body),
            'timeout' => 20,
        ]);
        if (is_wp_error($resp)) return $resp;
        return json_decode(wp_remote_retrieve_body($resp), true) ?? [];
    }

    private static function http_post_json($url, $body) {
        $resp = wp_remote_post($url, [
            'body'    => json_encode($body),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 20,
        ]);
        if (is_wp_error($resp)) return $resp;
        return json_decode(wp_remote_retrieve_body($resp), true) ?? [];
    }

    // ─── Cron ─────────────────────────────────────────────────────────────────

    public static function publish_due_posts() {
        $posts = get_posts([
            'post_type'      => self::CPT,
            'posts_per_page' => 10,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => 'post_status_dernek', 'value' => 'approved'],
                ['key' => 'scheduled_at', 'value' => '', 'compare' => '!='],
                ['key' => 'scheduled_at', 'value' => current_time('mysql'), 'compare' => '<=', 'type' => 'DATETIME'],
            ],
        ]);

        foreach ($posts as $post) {
            self::publish_post($post->ID);
        }
    }

    // ─── Sheets log ───────────────────────────────────────────────────────────

    private static function log_to_sheets($post_id, $data, $status) {
        $url = get_option('dernek_sheets_url', '');
        if (!$url) return;

        $content = !empty($data['content']) ? $data['content'] : get_post_field('post_content', $post_id);
        wp_remote_post($url, [
            'body'    => json_encode([
                'token'       => get_option('dernek_api_secret', ''),
                'action'      => 'logSocialPost',
                'post_id'     => $post_id,
                'account'     => get_post_meta($post_id, 'account_type', true),
                'platform'    => get_post_meta($post_id, 'platform', true),
                'content'     => mb_substr(strip_tags($content), 0, 300),
                'status'      => $status,
                'approved'    => in_array($status, ['approved', 'published']),
                'publish_url' => get_post_meta($post_id, 'publish_url', true),
                'ai_tool'     => get_post_meta($post_id, 'ai_tool_used', true),
            ]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10,
        ]);
    }
}
