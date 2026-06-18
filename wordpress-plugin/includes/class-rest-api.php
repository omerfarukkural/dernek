<?php
if (!defined('ABSPATH')) exit;

class Dernek_REST_API {
    public static function register_routes() {
        register_rest_route('dernek/v1', '/log',     ['methods' => 'POST', 'callback' => [self::class, 'handle_log'],    'permission_callback' => [self::class, 'auth']]);
        register_rest_route('dernek/v1', '/projects',['methods' => 'GET',  'callback' => [self::class, 'get_projects'],  'permission_callback' => '__return_true']);
        register_rest_route('dernek/v1', '/deploy',  ['methods' => 'POST', 'callback' => [self::class, 'handle_deploy'], 'permission_callback' => [self::class, 'auth']]);
    }

    public static function auth(WP_REST_Request $r) {
        $token = $r->get_header('X-Dernek-Token') ?: $r->get_param('token');
        $stored = get_option('dernek_api_secret', '');
        return !empty($stored) && hash_equals($stored, (string)$token);
    }

    public static function handle_log(WP_REST_Request $r) {
        $d = $r->get_json_params();
        if (empty($d)) return new WP_Error('no_data', 'Veri yok', ['status' => 400]);
        $name = sanitize_text_field($d['project'] ?? 'Genel');
        $ids = get_posts(['post_type' => 'ai_project', 'post_status' => 'any', 'title' => $name, 'numberposts' => 1, 'fields' => 'ids']);
        $id = $ids ? $ids[0] : wp_insert_post(['post_title' => $name, 'post_type' => 'ai_project', 'post_status' => 'draft']);
        if (is_wp_error($id)) return new WP_Error('fail', $id->get_error_message(), ['status' => 500]);
        update_post_meta($id, 'ai_tool',   sanitize_text_field($d['tool']   ?? ''));
        update_post_meta($id, 'ai_device', sanitize_text_field($d['device'] ?? ''));
        update_post_meta($id, 'last_activity', current_time('mysql'));
        $tok = (int)get_post_meta($id, 'tokens_used', true) + (int)($d['tokens_used'] ?? 0);
        update_post_meta($id, 'tokens_used', $tok);
        if (!empty($d['drive_link'])) update_post_meta($id, 'drive_link', esc_url_raw($d['drive_link']));
        return rest_ensure_response(['success' => true, 'post_id' => $id, 'tokens' => $tok, 'url' => get_permalink($id)]);
    }

    public static function handle_deploy(WP_REST_Request $r) {
        $d = $r->get_json_params();
        $id = wp_insert_post(['post_title' => sanitize_text_field(($d['domain'] ?? '') . ' — ' . ($d['action'] ?? '')), 'post_type' => 'ai_project', 'post_status' => 'private', 'post_content' => sanitize_textarea_field($d['notes'] ?? '')]);
        if (is_wp_error($id)) return new WP_Error('fail', $id->get_error_message(), ['status' => 500]);
        update_post_meta($id, 'ai_tool',   sanitize_text_field($d['tool'] ?? ''));
        update_post_meta($id, 'ai_stage',  'Deploy');
        update_post_meta($id, 'deploy_url', esc_url_raw($d['url'] ?? ''));
        return rest_ensure_response(['success' => true, 'post_id' => $id]);
    }

    public static function get_projects(WP_REST_Request $r) {
        $args = ['post_type' => 'ai_project', 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'modified', 'order' => 'DESC'];
        if ($t = $r->get_param('tool')) $args['meta_query'] = [['key' => 'ai_tool', 'value' => sanitize_text_field($t)]];
        return rest_ensure_response(array_map(function($p) {
            return ['id' => $p->ID, 'title' => $p->post_title, 'tool' => get_post_meta($p->ID, 'ai_tool', true), 'device' => get_post_meta($p->ID, 'ai_device', true), 'stage' => get_post_meta($p->ID, 'ai_stage', true), 'tokens' => (int)get_post_meta($p->ID, 'tokens_used', true), 'cost_usd' => (float)get_post_meta($p->ID, 'cost_usd', true), 'drive_link' => get_post_meta($p->ID, 'drive_link', true), 'url' => get_permalink($p->ID)];
        }, get_posts($args)));
    }
}
