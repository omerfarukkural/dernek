<?php
if (!defined('ABSPATH')) exit;

class Dernek_Post_Type {
    public static function register() {
        register_post_type('ai_project', [
            'labels' => [
                'name'               => 'AI Projeleri',
                'singular_name'      => 'AI Projesi',
                'add_new_item'       => 'Yeni AI Projesi Ekle',
                'edit_item'          => 'AI Projesini Duzenle',
                'not_found'          => 'Proje bulunamadi',
                'menu_name'          => 'AI Projeleri',
            ],
            'public'             => true,
            'show_in_rest'       => true,
            'supports'           => ['title', 'editor', 'custom-fields', 'thumbnail'],
            'menu_icon'          => 'dashicons-superhero',
            'rewrite'            => ['slug' => 'ai-projeler'],
            'has_archive'        => true,
        ]);
        self::register_meta();
    }

    private static function register_meta() {
        $auth = function() { return current_user_can('edit_posts'); };
        $strings = ['ai_tool', 'ai_device', 'ai_stage', 'drive_link', 'deploy_url', 'notebooklm_link', 'last_activity'];
        foreach ($strings as $m) {
            register_post_meta('ai_project', $m, [
                'show_in_rest' => true, 'single' => true, 'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field', 'auth_callback' => $auth,
            ]);
        }
        register_post_meta('ai_project', 'tokens_used', [
            'show_in_rest' => true, 'single' => true, 'type' => 'integer',
            'sanitize_callback' => 'absint', 'auth_callback' => $auth,
        ]);
        register_post_meta('ai_project', 'cost_usd', [
            'show_in_rest' => true, 'single' => true, 'type' => 'number',
            'sanitize_callback' => 'floatval', 'auth_callback' => $auth,
        ]);
    }
}
