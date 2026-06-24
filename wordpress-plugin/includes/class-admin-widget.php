<?php
if (!defined('ABSPATH')) exit;

class Dernek_Admin_Widget {
    public static function add_widget() {
        wp_add_dashboard_widget('dernek_ai_widget', 'Son AI Islemleri', [self::class, 'render']);
    }

    public static function render() {
        $posts = get_posts(['post_type' => 'ai_project', 'post_status' => 'any', 'numberposts' => 5, 'orderby' => 'modified', 'order' => 'DESC']);
        if (empty($posts)) { echo '<p>Henuz AI projesi yok.</p>'; return; }
        echo '<style>.daw td,.daw th{padding:6px 8px;font-size:12px;border-bottom:1px solid #eee}.daw th{background:#f5f5f5;font-weight:600}</style>';
        echo '<table class="daw" style="width:100%;border-collapse:collapse">';
        echo '<tr><th>Proje</th><th>Arac</th><th>Token</th><th>Tarih</th></tr>';
        $total = 0;
        foreach ($posts as $p) {
            $tok = (int)get_post_meta($p->ID, 'tokens_used', true);
            $total += $tok;
            printf('<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_url(get_edit_post_link($p->ID)), esc_html($p->post_title),
                esc_html(get_post_meta($p->ID, 'ai_tool', true) ?: '-'),
                esc_html(number_format($tok)), esc_html(date_i18n('d.m H:i', strtotime($p->post_modified))));
        }
        echo '<tr style="background:#e8f0fe"><td colspan="2"><strong>Toplam</strong></td><td><strong>' . esc_html(number_format($total)) . '</strong></td><td></td></tr>';
        echo '</table><p style="margin-top:6px;font-size:11px"><a href="' . esc_url(admin_url('edit.php?post_type=ai_project')) . '">Tum projeler</a></p>';
    }
}
