<?php
if (!defined('ABSPATH')) exit;

add_shortcode('dernek_ai_log', function($atts) {
    $atts = shortcode_atts(array(
        'tool'   => '',
        'limit'  => 10,
        'stage'  => '',
    ), $atts, 'dernek_ai_log');

    $args = array(
        'post_type'   => 'ai_project',
        'post_status' => 'any',
        'numberposts' => (int) $atts['limit'],
        'orderby'     => 'modified',
        'order'       => 'DESC',
    );
    $meta_query = array();
    if ($atts['tool']) {
        $meta_query[] = array('key' => 'ai_tool', 'value' => sanitize_text_field($atts['tool']), 'compare' => '=');
    }
    if ($atts['stage']) {
        $meta_query[] = array('key' => 'ai_stage', 'value' => sanitize_text_field($atts['stage']), 'compare' => '=');
    }
    if (!empty($meta_query)) {
        $args['meta_query'] = $meta_query;
    }
    $posts = get_posts($args);
    if (empty($posts)) {
        return '<p class="dernek-empty">Proje bulunamadi.</p>';
    }
    $out  = '<div class="dernek-ai-log">';
    $out .= '<table class="dernek-ai-table" style="width:100%;border-collapse:collapse;font-size:14px">';
    $out .= '<thead><tr style="background:#1a73e8;color:white"><th style="padding:10px">Proje</th><th>Arac</th><th>Asama</th><th>Token</th><th>Son Islem</th></tr></thead><tbody>';
    foreach ($posts as $i => $p) {
        $tool    = get_post_meta($p->ID, 'ai_tool', true) ?: '-';
        $stage   = get_post_meta($p->ID, 'ai_stage', true) ?: '-';
        $tokens  = number_format((int) get_post_meta($p->ID, 'tokens_used', true));
        $date    = date_i18n('d.m.Y', strtotime($p->post_modified));
        $bg      = $i % 2 === 0 ? '#f9f9f9' : '#fff';
        $out .= sprintf(
            '<tr style="background:%s"><td style="padding:8px"><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
            esc_attr($bg),
            esc_html($p->post_title),
            esc_html($tool),
            esc_html($stage),
            esc_html($tokens),
            esc_html($date)
        );
    }
    $out .= '</tbody></table></div>';
    return $out;
});
