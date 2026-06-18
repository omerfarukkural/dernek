<?php
if (!defined('ABSPATH')) exit;

class Dernek_Admin_Widget {
    public static function add_widget() {
        wp_add_dashboard_widget(
            'dernek_ai_widget',
            'Son AI Islemleri',
            array(self::class, 'render')
        );
    }

    public static function render() {
        $posts = get_posts(array(
            'post_type'   => 'ai_project',
            'post_status' => 'any',
            'numberposts' => 5,
            'orderby'     => 'modified',
            'order'       => 'DESC',
        ));
        if (empty($posts)) {
            echo '<p>Henuz AI projesi yok.</p>';
            return;
        }
        echo '<style>.dernek-widget td,.dernek-widget th{padding:6px 8px;font-size:12px;border-bottom:1px solid #eee}.dernek-widget th{background:#f5f5f5;font-weight:600}</style>';
        echo '<table class="dernek-widget" style="width:100%;border-collapse:collapse">';
        echo '<tr><th>Proje</th><th>Arac</th><th>Token</th><th>Son Islem</th></tr>';
        foreach ($posts as $p) {
            $tool   = get_post_meta($p->ID, 'ai_tool', true) ?: '-';
            $tokens = number_format((int) get_post_meta($p->ID, 'tokens_used', true));
            $date   = date_i18n('d.m H:i', strtotime($p->post_modified));
            $edit   = get_edit_post_link($p->ID);
            printf(
                '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_url($edit),
                esc_html($p->post_title),
                esc_html($tool),
                esc_html($tokens),
                esc_html($date)
            );
        }
        $total_tokens = array_sum(array_map(function($p) { return (int) get_post_meta($p->ID, 'tokens_used', true); }, $posts));
        echo '<tr style="background:#f0f4ff"><td colspan="2"><strong>Toplam (son 5)</strong></td><td><strong>' . number_format($total_tokens) . '</strong></td><td></td></tr>';
        echo '</table>';
        echo '<p style="margin-top:8px;font-size:11px"><a href="' . admin_url('edit.php?post_type=ai_project') . '">Tum projeleri gor</a></p>';
    }
}
