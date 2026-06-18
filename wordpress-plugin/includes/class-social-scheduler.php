<?php
/**
 * Dernek Social Scheduler
 * Threads, Facebook, Instagram için doğrudan API entegrasyonu
 * JeSuspended bağımlılığı yok — tamamen bağımsız çalışır
 */
if (!defined('ABSPATH')) exit;

class Dernek_Social_Scheduler {

    const CPT = 'social_post';

    public static function register_cpt() {
        register_post_type(self::CPT, [
            'labels'       => [
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
        register_rest_route('dernek/v1', '/telegram-webhook', [
            'methods'             => ['POST', 'GET'],
            'callback'            => [self::class, 'telegram_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    // ─── REST handlers ────────────────────────────────────────────────────────

    public static function api_create(\WP_REST_Request $request) {
        $d = $request->get_json_params();
        if (empty($d['content'])) {
            return new \WP_Error('missing_content', 'İçerik gerekli', ['status' => 400]);
        }

        $post_id = wp_insert_post([
            'post_type'    => self::CPT,
            'post_title'   => mb_substr(strip_tags($d['content']), 0, 80),
            'post_content' => wp_kses_post($d['content']),
            'post_status'  => 'publish',
        ]);

        if (is_wp_error($post_id)) return $post_id;

        $platform     = sanitize_text_field($d['platform']     ?? 'threads');
        $account_type = sanitize_text_field($d['account_type'] ?? 'personal');

        update_post_meta($post_id, 'platform',           $platform);
        update_post_meta($post_id, 'account_type',       $account_type);
        update_post_meta($post_id, 'scheduled_at',       sanitize_text_field($d['scheduled_at'] ?? ''));
        update_post_meta($post_id, 'post_status_dernek', 'pending_approval');
        update_post_meta($post_id, 'ai_tool_used',       sanitize_text_field($d['ai_tool'] ?? 'pipeline'));

        $tg = self::send_telegram_approval($post_id, $d['content']);
        self::log_to_sheets($post_id, $d, 'pending_approval');

        return rest_ensure_response([
            'success'       => true,
            'post_id'       => $post_id,
            'status'        => 'pending_approval',
            'telegram_sent' => !is_wp_error($tg),
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

        if (!empty($d['publish_url'])) {
            update_post_meta($post_id, 'publish_url', esc_url_raw($d['publish_url']));
        }

        return rest_ensure_response(['success' => true, 'post_id' => $post_id]);
    }

    public static function api_list(\WP_REST_Request $request) {
        $status = sanitize_text_field($request->get_param('status') ?? 'pending_approval');
        $query  = new \WP_Query([
            'post_type'      => self::CPT,
            'posts_per_page' => 50,
            'meta_query'     => [['key' => 'post_status_dernek', 'value' => $status]],
        ]);

        $posts = [];
        foreach ($query->posts as $p) {
            $posts[] = [
                'id'           => $p->ID,
                'content'      => $p->post_content,
                'platform'     => get_post_meta($p->ID, 'platform', true),
                'account_type' => get_post_meta($p->ID, 'account_type', true),
                'scheduled_at' => get_post_meta($p->ID, 'scheduled_at', true),
                'status'       => get_post_meta($p->ID, 'post_status_dernek', true),
                'ai_tool'      => get_post_meta($p->ID, 'ai_tool_used', true),
                'publish_url'  => get_post_meta($p->ID, 'publish_url', true),
                'created'      => get_the_date('c', $p->ID),
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

        $labels = ['personal' => '👤 Kişisel', 'dernek' => '🏢 Dernek', 'viral' => '🔥 Viral'];
        $pl     = strtoupper($platform);
        $acc    = $labels[$account_type] ?? $account_type;

        $text  = "🤖 *Yeni Gönderi Onay Bekliyor*\n\n";
        $text .= "📱 Platform: *$pl*\n";
        $text .= "👥 Hesap: *$acc*\n";
        if ($scheduled_at) $text .= "⏰ Planlandı: *$scheduled_at*\n";
        $text .= "\n📝 *İçerik:*\n" . mb_substr(strip_tags($content), 0, 800);
        if (mb_strlen($content) > 800) $text .= "…";
        $text .= "\n\n🆔 `post:$post_id`";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Onayla ve Yayınla', 'callback_data' => "approve_$post_id"],
                    ['text' => '❌ Reddet',             'callback_data' => "reject_$post_id"],
                ],
                [
                    ['text' => '⏰ 1 Saat Ertele',     'callback_data' => "delay1h_$post_id"],
                    ['text' => '⏰ 1 Gün Ertele',      'callback_data' => "delay1d_$post_id"],
                ],
                [
                    ['text' => '✏️ WP\'de Düzenle',    'callback_data' => "editlink_$post_id"],
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

        // Spinner'ı kapat
        if ($token) {
            wp_remote_post("https://api.telegram.org/bot$token/answerCallbackQuery", [
                'body'    => json_encode(['callback_query_id' => $cb['id']]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 5,
            ]);
        }

        if (!preg_match('/^(approve|reject|delay1h|delay1d|editlink)_(\d+)$/', $data, $m)) {
            return rest_ensure_response(['ok' => true]);
        }

        [$full, $action, $post_id] = $m;
        $post_id = (int) $post_id;

        switch ($action) {
            case 'approve':
                update_post_meta($post_id, 'post_status_dernek', 'approved');
                $scheduled = get_post_meta($post_id, 'scheduled_at', true);
                if (!$scheduled || strtotime($scheduled) <= time()) {
                    $result = self::publish_post($post_id);
                    $reply  = is_wp_error($result)
                        ? "⚠️ Onaylandı fakat yayın hatası: " . $result->get_error_message()
                        : "✅ Post #$post_id onaylandı ve yayınlandı!";
                } else {
                    $reply = "✅ Post #$post_id onaylandı. $scheduled tarihinde yayınlanacak.";
                }
                break;

            case 'reject':
                update_post_meta($post_id, 'post_status_dernek', 'rejected');
                $reply = "❌ Post #$post_id reddedildi.";
                break;

            case 'delay1h':
                $new = date('Y-m-d H:i:s', time() + 3600);
                update_post_meta($post_id, 'scheduled_at', $new);
                update_post_meta($post_id, 'post_status_dernek', 'approved');
                $reply = "⏰ Post #$post_id 1 saat ertelendi: $new";
                break;

            case 'delay1d':
                $new = date('Y-m-d H:i:s', time() + 86400);
                update_post_meta($post_id, 'scheduled_at', $new);
                update_post_meta($post_id, 'post_status_dernek', 'approved');
                $reply = "⏰ Post #$post_id 1 gün ertelendi: $new";
                break;

            case 'editlink':
                $url   = admin_url("post.php?post=$post_id&action=edit");
                $reply = "✏️ Düzenleme linki:\n$url";
                break;

            default:
                $reply = '';
        }

        self::tg_send($token, $cb['message']['chat']['id'] ?? null, $reply, $cb['message']['message_id'] ?? null);
        self::log_to_sheets($post_id, [], get_post_meta($post_id, 'post_status_dernek', true));

        return rest_ensure_response(['ok' => true]);
    }

    private static function tg_send($token, $chat_id, $text, $original_message_id = null) {
        if (!$token || !$chat_id || !$text) return;

        // Onay butonlarını kaldır
        if ($original_message_id) {
            wp_remote_post("https://api.telegram.org/bot$token/editMessageReplyMarkup", [
                'body'    => json_encode([
                    'chat_id'      => $chat_id,
                    'message_id'   => $original_message_id,
                    'reply_markup' => ['inline_keyboard' => []],
                ]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 5,
            ]);
        }

        wp_remote_post("https://api.telegram.org/bot$token/sendMessage", [
            'body'    => json_encode(['chat_id' => $chat_id, 'text' => $text]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10,
        ]);
    }

    // ─── Publish ──────────────────────────────────────────────────────────────

    public static function publish_post($post_id) {
        $content      = get_post_field('post_content', $post_id);
        $platform     = get_post_meta($post_id, 'platform', true);
        $account_type = get_post_meta($post_id, 'account_type', true);

        update_post_meta($post_id, 'post_status_dernek', 'publishing');

        switch ($platform) {
            case 'threads':
                $result = self::publish_threads($post_id, $content, $account_type);
                break;
            case 'facebook':
                $result = self::publish_facebook($post_id, $content, $account_type);
                break;
            case 'instagram':
                $result = self::publish_instagram($post_id, $content, $account_type);
                break;
            default:
                $result = new \WP_Error('unsupported', "Platform desteklenmiyor: $platform");
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

    // Threads API (Meta Graph API)
    private static function publish_threads($post_id, $content, $account_type) {
        $accounts     = json_decode(get_option('dernek_social_accounts', '{}'), true);
        $account_conf = $accounts[$account_type]['threads'] ?? null;

        if (!$account_conf) {
            return new \WP_Error('no_account', "Threads hesabı tanımlı değil: $account_type");
        }

        $user_id     = $account_conf['user_id']     ?? $account_conf;
        $access_token = $account_conf['access_token'] ?? get_option('dernek_threads_token_' . $account_type, '');

        if (!$access_token) {
            return new \WP_Error('no_token', "Threads access token eksik: $account_type");
        }

        // Adım 1: Media container oluştur
        $container = wp_remote_post("https://graph.threads.net/v1.0/$user_id/threads", [
            'body'    => [
                'media_type'   => 'TEXT',
                'text'         => mb_substr($content, 0, 500),
                'access_token' => $access_token,
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($container)) return $container;
        $container_body = json_decode(wp_remote_retrieve_body($container), true);
        if (empty($container_body['id'])) {
            return new \WP_Error('threads_container_fail', $container_body['error']['message'] ?? 'Container oluşturulamadı');
        }

        sleep(2); // Meta API'nin container'ı işlemesi için bekle

        // Adım 2: Yayınla
        $publish = wp_remote_post("https://graph.threads.net/v1.0/$user_id/threads_publish", [
            'body'    => [
                'creation_id'  => $container_body['id'],
                'access_token' => $access_token,
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($publish)) return $publish;
        $pub_body = json_decode(wp_remote_retrieve_body($publish), true);

        if (empty($pub_body['id'])) {
            return new \WP_Error('threads_publish_fail', $pub_body['error']['message'] ?? 'Yayın başarısız');
        }

        return [
            'id'  => $pub_body['id'],
            'url' => "https://www.threads.net/@$user_id",
        ];
    }

    // Facebook Page API
    private static function publish_facebook($post_id, $content, $account_type) {
        $accounts     = json_decode(get_option('dernek_social_accounts', '{}'), true);
        $account_conf = $accounts[$account_type]['facebook'] ?? null;

        if (!$account_conf) {
            return new \WP_Error('no_account', "Facebook hesabı tanımlı değil: $account_type");
        }

        $page_id      = $account_conf['page_id']      ?? $account_conf;
        $access_token = $account_conf['access_token']  ?? get_option('dernek_fb_token_' . $account_type, '');

        if (!$access_token) {
            return new \WP_Error('no_token', "Facebook access token eksik: $account_type");
        }

        $resp = wp_remote_post("https://graph.facebook.com/v21.0/$page_id/feed", [
            'body'    => [
                'message'      => mb_substr($content, 0, 2000),
                'access_token' => $access_token,
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($resp)) return $resp;
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if (empty($body['id'])) {
            return new \WP_Error('fb_fail', $body['error']['message'] ?? 'Facebook yayın başarısız');
        }

        return [
            'id'  => $body['id'],
            'url' => "https://www.facebook.com/$page_id",
        ];
    }

    // Instagram API (Graph API üzerinden)
    private static function publish_instagram($post_id, $content, $account_type) {
        $accounts     = json_decode(get_option('dernek_social_accounts', '{}'), true);
        $account_conf = $accounts[$account_type]['instagram'] ?? null;

        if (!$account_conf) {
            return new \WP_Error('no_account', "Instagram hesabı tanımlı değil: $account_type");
        }

        $ig_user_id   = $account_conf['user_id']      ?? $account_conf;
        $access_token = $account_conf['access_token']  ?? get_option('dernek_ig_token_' . $account_type, '');

        if (!$access_token) {
            return new \WP_Error('no_token', "Instagram access token eksik: $account_type");
        }

        // IG sadece medya ile çalışır; caption only için bir placeholder image gerekli
        // Şimdilik sadece caption bilgisini döndürüyoruz, medya destekli versiyonu için image_url gerekli
        $resp = wp_remote_post("https://graph.facebook.com/v21.0/$ig_user_id/media", [
            'body'    => [
                'caption'      => mb_substr($content, 0, 2200),
                'media_type'   => 'REELS', // veya IMAGE
                'access_token' => $access_token,
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($resp)) return $resp;
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if (empty($body['id'])) {
            return new \WP_Error('ig_media_fail', $body['error']['message'] ?? 'Instagram media container başarısız');
        }

        // Yayınla
        $pub = wp_remote_post("https://graph.facebook.com/v21.0/$ig_user_id/media_publish", [
            'body'    => ['creation_id' => $body['id'], 'access_token' => $access_token],
            'timeout' => 20,
        ]);

        if (is_wp_error($pub)) return $pub;
        $pub_body = json_decode(wp_remote_retrieve_body($pub), true);

        if (empty($pub_body['id'])) {
            return new \WP_Error('ig_publish_fail', $pub_body['error']['message'] ?? 'Instagram yayın başarısız');
        }

        return ['id' => $pub_body['id'], 'url' => "https://www.instagram.com/"];
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

        $content  = !empty($data['content']) ? $data['content'] : get_post_field('post_content', $post_id);
        wp_remote_post($url, [
            'body'    => json_encode([
                'token'       => get_option('dernek_api_secret', '571632'),
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
