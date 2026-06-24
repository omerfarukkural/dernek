<?php
if (!defined('ABSPATH')) exit;

add_shortcode('dernek_ai_log', function($atts) {
    $a = shortcode_atts(['tool' => '', 'limit' => 10, 'stage' => ''], $atts, 'dernek_ai_log');
    $args = ['post_type' => 'ai_project', 'post_status' => 'any', 'numberposts' => (int)$a['limit'], 'orderby' => 'modified', 'order' => 'DESC'];
    $mq = [];
    if ($a['tool'])  $mq[] = ['key' => 'ai_tool',  'value' => sanitize_text_field($a['tool'])];
    if ($a['stage']) $mq[] = ['key' => 'ai_stage', 'value' => sanitize_text_field($a['stage'])];
    if ($mq) $args['meta_query'] = $mq;
    $posts = get_posts($args);
    if (empty($posts)) return '<p class="dernek-no-projects">Proje bulunamadi.</p>';
    $o = '<div class="dernek-ai-log"><table style="width:100%;border-collapse:collapse;font-size:14px">';
    $o .= '<thead><tr style="background:#1a73e8;color:#fff"><th style="padding:10px;text-align:left">Proje</th><th>Arac</th><th>Asama</th><th>Token</th><th>Tarih</th></tr></thead><tbody>';
    foreach ($posts as $i => $p) {
        $bg = $i % 2 === 0 ? '#f9f9f9' : '#fff';
        $o .= sprintf('<tr style="background:%s"><td style="padding:8px"><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
            esc_attr($bg), esc_html($p->post_title),
            esc_html(get_post_meta($p->ID, 'ai_tool',  true) ?: '-'),
            esc_html(get_post_meta($p->ID, 'ai_stage', true) ?: '-'),
            number_format((int)get_post_meta($p->ID, 'tokens_used', true)),
            esc_html(date_i18n('d.m.Y', strtotime($p->post_modified))));
    }
    return $o . '</tbody></table></div>';
});
