<?php
if (!defined('ABSPATH')) exit;

class Dernek_REST_API {
    public static function register_routes() {
        register_rest_route('dernek/v1', '/log', array(
            'methods'             => 'POST',
            'callback'            => array(self::class, 'handle_log'),
            'permission_callback' => array(self::class, 'check_permission'),
        ));
        register_rest_route('dernek/v1', '/projects', array(
            'methods'             => 'GET',
            'callback'            => array(self::class, 'get_projects'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('dernek/v1', '/deploy', array(
            'methods'             => 'POST',
            'callback'            => array(self::class, 'handle_deploy_log'),
            'permission_callback' => array(self::class, 'check_permission'),
        ));
    }

    public static function check_permission(WP_REST_Request $request) {
        $token = $request->get_header('X-Dernek-Token');
        if (!$token) {
            $token = $request->get_param('token');
        }
        $stored_secret = get_option('dernek_api_secret', '');
        if (empty($stored_secret)) {
            return false;
        }
        return hash_equals($stored_secret, $token);
    }

    public static function handle_log(WP_REST_Request $request) {
        $data = $request->get_json_params();
        if (empty($data)) {
            return new WP_Error('no_data', 'Veri bulunamadi', array('status' => 400));
        }
        $project_name = sanitize_text_field($data['project'] ?? 'Genel');
        $existing = get_posts(array(
            'post_type'    => 'ai_project',
            'post_status'  => 'any',
            'meta_query'   => array(),
            'title'        => $project_name,
            'numberposts'  => 1,
            'fields'       => 'ids',
        ));
        if (!empty($existing)) {
            $post_id = $existing[0];
        } else {
            $post_id = wp_insert_post(array(
                'post_title'  => $project_name,
                'post_type'   => 'ai_project',
                'post_status' => 'draft',
                'post_content' => '',
            ));
        }
        if (is_wp_error($post_id)) {
            return new WP_Error('create_failed', $post_id->get_error_message(), array('status' => 500));
        }
        update_post_meta($post_id, 'ai_tool', sanitize_text_field($data['tool'] ?? ''));
        update_post_meta($post_id, 'ai_device', sanitize_text_field($data['device'] ?? ''));
        update_post_meta($post_id, 'ai_stage', sanitize_text_field($data['stage'] ?? 'Devam Ediyor'));
        update_post_meta($post_id, 'last_activity', current_time('mysql'));
        $current_tokens = (int) get_post_meta($post_id, 'tokens_used', true);
        $new_tokens = $current_tokens + (int)($data['tokens_used'] ?? 0);
        update_post_meta($post_id, 'tokens_used', $new_tokens);
        $current_cost = (float) get_post_meta($post_id, 'cost_usd', true);
        $new_cost = $current_cost + (float)($data['cost_usd'] ?? 0);
        update_post_meta($post_id, 'cost_usd', $new_cost);
        if (!empty($data['drive_link'])) {
            update_post_meta($post_id, 'drive_link', esc_url_raw($data['drive_link']));
        }
        return rest_ensure_response(array(
            'success'  => true,
            'post_id'  => $post_id,
            'url'      => get_permalink($post_id),
            'tokens'   => $new_tokens,
        ));
    }

    public static function handle_deploy_log(WP_REST_Request $request) {
        $data = $request->get_json_params();
        $log_entry = array(
            'post_title'   => sanitize_text_field(($data['domain'] ?? '') . ' - ' . ($data['action'] ?? '')),
            'post_type'    => 'ai_project',
            'post_status'  => 'private',
            'post_content' => sanitize_textarea_field($data['notes'] ?? ''),
        );
        $post_id = wp_insert_post($log_entry);
        if (is_wp_error($post_id)) {
            return new WP_Error('log_failed', $post_id->get_error_message(), array('status' => 500));
        }
        update_post_meta($post_id, 'ai_tool', sanitize_text_field($data['tool'] ?? ''));
        update_post_meta($post_id, 'ai_stage', 'Deploy');
        update_post_meta($post_id, 'deploy_url', esc_url_raw($data['url'] ?? ''));
        return rest_ensure_response(array('success' => true, 'post_id' => $post_id));
    }

    public static function get_projects(WP_REST_Request $request) {
        $args = array(
            'post_type'   => 'ai_project',
            'post_status' => 'any',
            'numberposts' => -1,
            'orderby'     => 'modified',
            'order'       => 'DESC',
        );
        $tool_filter = $request->get_param('tool');
        if ($tool_filter) {
            $args['meta_query'] = array(
                array('key' => 'ai_tool', 'value' => sanitize_text_field($tool_filter), 'compare' => '=')
            );
        }
        $posts = get_posts($args);
        $result = array_map(function($p) {
            return array(
                'id'          => $p->ID,
                'title'       => $p->post_title,
                'tool'        => get_post_meta($p->ID, 'ai_tool', true),
                'device'      => get_post_meta($p->ID, 'ai_device', true),
                'stage'       => get_post_meta($p->ID, 'ai_stage', true),
                'tokens'      => (int) get_post_meta($p->ID, 'tokens_used', true),
                'cost_usd'    => (float) get_post_meta($p->ID, 'cost_usd', true),
                'drive_link'  => get_post_meta($p->ID, 'drive_link', true),
                'deploy_url'  => get_post_meta($p->ID, 'deploy_url', true),
                'modified'    => $p->post_modified,
                'url'         => get_permalink($p->ID),
            );
        }, $posts);
        return rest_ensure_response($result);
    }
}
