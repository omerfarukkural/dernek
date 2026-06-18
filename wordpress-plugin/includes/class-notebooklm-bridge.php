<?php
/**
 * NotebookLM → Google Drive → Claude köprüsü
 * Token tasarrufu: daha önce araştırılan konular Drive'dan çekilir
 */
if (!defined('ABSPATH')) exit;

class Dernek_NotebookLM_Bridge {

    public static function register_routes() {
        register_rest_route('dernek/v1', '/save-research', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'save_research_endpoint'],
            'permission_callback' => ['Dernek_Social_Scheduler', 'auth'],
        ]);
        register_rest_route('dernek/v1', '/get-context', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_context_endpoint'],
            'permission_callback' => ['Dernek_Social_Scheduler', 'auth'],
        ]);
    }

    public static function save_research_endpoint(\WP_REST_Request $request) {
        $data    = $request->get_json_params();
        $content = sanitize_textarea_field($data['content'] ?? '');
        $topic   = sanitize_text_field($data['topic'] ?? 'Genel');
        $source  = sanitize_text_field($data['source'] ?? 'manual');

        if (!$content) {
            return new \WP_Error('empty_content', 'İçerik gerekli', ['status' => 400]);
        }

        $result = self::save_research_to_drive($content, $topic, $source);

        return rest_ensure_response([
            'success' => !is_wp_error($result),
            'message' => is_wp_error($result) ? $result->get_error_message() : 'Drive\'a kaydedildi',
        ]);
    }

    public static function get_context_endpoint(\WP_REST_Request $request) {
        $topic = sanitize_text_field($request->get_param('topic') ?? '');
        $context = self::get_context_for_topic($topic);

        return rest_ensure_response(['context' => $context]);
    }

    /**
     * Araştırma içeriğini Google Drive'a Apps Script aracılığıyla kaydeder
     */
    public static function save_research_to_drive($content, $topic, $source = 'wp') {
        $sheets_url = get_option('dernek_sheets_url', '');
        if (!$sheets_url) {
            return new \WP_Error('no_sheets_url', 'Apps Script URL eksik');
        }

        $response = wp_remote_post($sheets_url, [
            'body'    => json_encode([
                'token'    => get_option('dernek_api_secret', '571632'),
                'action'   => 'saveToDrive',
                'project'  => $topic,
                'tool'     => $source,
                'device'   => 'wordpress',
                'prompt_summary'   => "Araştırma: $topic",
                'response_summary' => substr($content, 0, 2000),
            ]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            return new \WP_Error('sheets_error', "HTTP $code");
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Apps Script'ten benzer geçmiş araştırmaları çeker
     */
    public static function get_context_for_topic($topic) {
        $sheets_url = get_option('dernek_sheets_url', '');
        if (!$sheets_url) return '';

        $url = add_query_arg([
            'action' => 'getContext',
            'token'  => get_option('dernek_api_secret', '571632'),
            'topic'  => urlencode($topic),
        ], $sheets_url);

        $response = wp_remote_get($url, ['timeout' => 10]);

        if (is_wp_error($response)) return '';

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['context'] ?? '';
    }

    /**
     * NotebookLM bağlamını Claude prompt'una ekler (token tasarrufu)
     */
    public static function build_claude_prompt_with_context($prompt, $topic) {
        $context = self::get_context_for_topic($topic);

        if (empty($context)) {
            return $prompt;
        }

        return "Aşağıda bu konu hakkında daha önce yapılmış araştırma var. " .
               "Bu bağlamı kullanarak yanıt ver:\n\n" .
               "=== GEÇMİŞ ARAŞTIRMA ===\n$context\n" .
               "========================\n\n" .
               $prompt;
    }
}
