<?php
if (!defined('ABSPATH')) exit;

class Dernek_Post_Type {
    public static function register() {
        $labels = array(
            'name'               => 'AI Projeleri',
            'singular_name'      => 'AI Projesi',
            'add_new'            => 'Yeni Ekle',
            'add_new_item'       => 'Yeni AI Projesi Ekle',
            'edit_item'          => 'AI Projesini Duzenle',
            'new_item'           => 'Yeni AI Projesi',
            'view_item'          => 'AI Projesini Goruntule',
            'search_items'       => 'AI Projelerini Ara',
            'not_found'          => 'Proje bulunamadi',
            'not_found_in_trash' => 'Copte proje bulunamadi',
            'menu_name'          => 'AI Projeleri',
        );
        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'ai-projeler'),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 25,
            'menu_icon'          => 'dashicons-superhero',
            'supports'           => array('title', 'editor', 'custom-fields', 'thumbnail'),
            'show_in_rest'       => true,
        );
        register_post_type('ai_project', $args);
        self::register_meta();
    }

    private static function register_meta() {
        $string_metas = array('ai_tool', 'ai_device', 'ai_stage', 'drive_link', 'deploy_url', 'notebooklm_link', 'last_activity');
        foreach ($string_metas as $meta) {
            register_post_meta('ai_project', $meta, array(
                'show_in_rest'      => true,
                'single'            => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback'     => function() { return current_user_can('edit_posts'); }
            ));
        }
        $int_metas = array('tokens_used');
        foreach ($int_metas as $meta) {
            register_post_meta('ai_project', $meta, array(
                'show_in_rest'      => true,
                'single'            => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'auth_callback'     => function() { return current_user_can('edit_posts'); }
            ));
        }
        $number_metas = array('cost_usd');
        foreach ($number_metas as $meta) {
            register_post_meta('ai_project', $meta, array(
                'show_in_rest'      => true,
                'single'            => true,
                'type'              => 'number',
                'sanitize_callback' => 'floatval',
                'auth_callback'     => function() { return current_user_can('edit_posts'); }
            ));
        }
    }
}
