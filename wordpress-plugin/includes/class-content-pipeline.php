<?php
/**
 * AI içerik üretim pipeline: Perplexity → Gemini → Claude
 */
if (!defined('ABSPATH')) exit;

class Dernek_Content_Pipeline {

    const CHAR_LIMITS = [
        'threads'   => 500,
        'facebook'  => 2000,
        'instagram' => 2200,
        'twitter'   => 280,
    ];

    const TONE_GUIDELINES = [
        'personal' => 'Samimi, düşündürücü, kişisel deneyim odaklı. Türkçe. Ömer Faruk Kuralın sesini yansıt. Birinci şahıs kullan.',
        'dernek'   => 'Kurumsal, güvenilir, toplumu bilgilendirici. Türkçe. Sivil toplum ve dernek dili. Çoğul birinci şahıs kullan.',
        'viral'    => 'Dikkat çekici hook ile başla, trend odaklı, soru sor, emoji kullan, call-to-action ile bitir. Türkçe.',
    ];

    public static function register_routes() {
        register_rest_route('dernek/v1', '/pipeline/run', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'run_pipeline'],
            'permission_callback' => ['Dernek_Social_Scheduler', 'auth'],
        ]);
        register_rest_route('dernek/v1', '/pipeline/research', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'research_only'],
            'permission_callback' => ['Dernek_Social_Scheduler', 'auth'],
        ]);
    }

    public static function run_pipeline(\WP_REST_Request $request) {
        $data         = $request->get_json_params();
        $topic        = sanitize_text_field($data['topic'] ?? '');
        $account_type = sanitize_text_field($data['account_type'] ?? 'personal');
        $platforms    = array_map('sanitize_text_field', $data['platforms'] ?? ['threads']);

        if (!$topic) {
            return new \WP_Error('missing_topic', 'Konu gerekli', ['status' => 400]);
        }

        $result = self::create_pipeline($topic, $platforms, $account_type);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function research_only(\WP_REST_Request $request) {
        $data         = $request->get_json_params();
        $topic        = sanitize_text_field($data['topic'] ?? '');
        $account_type = sanitize_text_field($data['account_type'] ?? 'personal');

        $research = self::research_with_perplexity($topic, $account_type);
        if (is_wp_error($research)) {
            return $research;
        }

        return rest_ensure_response(['research' => $research]);
    }

    public static function create_pipeline($topic, $platforms, $account_type) {
        // Adım 1: Perplexity araştırması
        $research = self::research_with_perplexity($topic, $account_type);
        if (is_wp_error($research)) {
            return $research;
        }

        $created_posts = [];

        foreach ($platforms as $platform) {
            $limit = self::CHAR_LIMITS[$platform] ?? 500;

            // Adım 2: Gemini içerik üretimi
            $generated = self::generate_with_gemini($research, $platform, $account_type, $limit);
            if (is_wp_error($generated)) {
                $created_posts[] = ['platform' => $platform, 'error' => $generated->get_error_message()];
                continue;
            }

            // Adım 3: Claude ile son rötuş
            $polished = self::refine_with_claude($generated, self::TONE_GUIDELINES[$account_type]);
            $final_content = is_wp_error($polished) ? $generated : $polished;

            // Adım 4: WordPress'e kaydet ve Telegram onayı gönder
            $post_id = wp_insert_post([
                'post_type'    => Dernek_Social_Scheduler::CPT,
                'post_title'   => "$topic — $platform — $account_type",
                'post_content' => sanitize_textarea_field($final_content),
                'post_status'  => 'publish',
            ]);

            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, 'platform',           $platform);
                update_post_meta($post_id, 'account_type',       $account_type);
                update_post_meta($post_id, 'post_status_dernek', 'pending_approval');
                update_post_meta($post_id, 'ai_tool_used',       'pipeline:perplexity+gemini+claude');

                Dernek_Social_Scheduler::send_telegram_approval($post_id, $final_content);

                // Drive'a araştırmayı kaydet
                Dernek_NotebookLM_Bridge::save_research_to_drive(
                    "# $topic\n\n## Araştırma\n$research\n\n## Üretilen İçerik ($platform)\n$final_content",
                    $topic,
                    'pipeline'
                );

                $created_posts[] = [
                    'platform'   => $platform,
                    'post_id'    => $post_id,
                    'status'     => 'pending_approval',
                    'preview'    => substr($final_content, 0, 150),
                ];
            }
        }

        return [
            'success'       => true,
            'topic'         => $topic,
            'account_type'  => $account_type,
            'posts_created' => count($created_posts),
            'posts'         => $created_posts,
        ];
    }

    public static function research_with_perplexity($topic, $account_type) {
        $api_key = get_option('dernek_perplexity_api_key', '');
        if (!$api_key) {
            return new \WP_Error('no_perplexity_key', 'Perplexity API anahtarı eksik');
        }

        $system_prompt = "Sen Türkçe sosyal medya içerik araştırmacısısın. Kısa, özlü ve güncel bilgi ver.";
        $user_prompt   = "Bu konu hakkında Türkçe sosyal medya içeriği oluşturmak için araştırma yap. " .
                         "Hesap türü: $account_type. " .
                         "Konu: $topic. " .
                         "En önemli 5 nokta, güncel trend ve önerilen açı ile yanıt ver.";

        $response = wp_remote_post('https://api.perplexity.ai/chat/completions', [
            'body'    => json_encode([
                'model'    => 'sonar-pro',
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user',   'content' => $user_prompt],
                ],
                'max_tokens' => 800,
            ]),
            'headers' => [
                'Authorization' => "Bearer $api_key",
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) return $response;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 400) {
            return new \WP_Error('perplexity_error', $body['error']['message'] ?? "HTTP $code");
        }

        return $body['choices'][0]['message']['content'] ?? '';
    }

    public static function generate_with_gemini($research, $platform, $account_type, $char_limit) {
        $api_key = get_option('dernek_gemini_api_key', '');
        if (!$api_key) {
            return new \WP_Error('no_gemini_key', 'Gemini API anahtarı eksik');
        }

        $tone    = self::TONE_GUIDELINES[$account_type];
        $prompt  = "Aşağıdaki araştırmayı kullanarak $platform için Türkçe sosyal medya gönderisi yaz.\n\n";
        $prompt .= "Ton: $tone\n";
        $prompt .= "Karakter limiti: $char_limit\n";
        $prompt .= "Platform: $platform\n\n";
        $prompt .= "Araştırma:\n$research\n\n";
        $prompt .= "Sadece gönderi metnini yaz, başka açıklama ekleme.";

        $response = wp_remote_post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=$api_key",
            [
                'body'    => json_encode([
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['maxOutputTokens' => 600, 'temperature' => 0.7],
                ]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) return $response;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 400) {
            return new \WP_Error('gemini_error', $body['error']['message'] ?? "HTTP $code");
        }

        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return substr($text, 0, $char_limit);
    }

    public static function refine_with_claude($content, $tone_guidelines) {
        $api_key = get_option('dernek_claude_api_key', '');
        if (!$api_key) {
            return new \WP_Error('no_claude_key', 'Claude API anahtarı eksik');
        }

        $prompt = "Bu sosyal medya gönderisini aşağıdaki ton kurallarına göre iyileştir. " .
                  "Sadece iyileştirilmiş metni yaz.\n\n" .
                  "Ton: $tone_guidelines\n\n" .
                  "Metin:\n$content";

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'body'    => json_encode([
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 600,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]),
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) return $response;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 400) {
            return new \WP_Error('claude_error', $body['error']['message'] ?? "HTTP $code");
        }

        return $body['content'][0]['text'] ?? $content;
    }
}
