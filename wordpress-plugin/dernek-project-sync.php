<?php
/**
 * Plugin Name: Dernek AI Proje Senkronizasyonu
 * Plugin URI:  https://bitebimuv.org
 * Description: AI proje takip sistemini WordPress ile entegre eder. Tum cihazlardan gelen AI konusma loglarini WordPress'e aktarir.
 * Version:     1.0.0
 * Author:      Dernek
 * Text Domain: dernek-sync
 * License:     GPL-2.0+
 */

if (!defined('ABSPATH')) exit;

define('DERNEK_SYNC_VERSION', '1.0.0');
define('DERNEK_SYNC_PATH', plugin_dir_path(__FILE__));
define('DERNEK_SYNC_URL',  plugin_dir_url(__FILE__));

require_once DERNEK_SYNC_PATH . 'includes/class-post-type.php';
require_once DERNEK_SYNC_PATH . 'includes/class-rest-api.php';
require_once DERNEK_SYNC_PATH . 'includes/class-admin-widget.php';
require_once DERNEK_SYNC_PATH . 'public/shortcode.php';

add_action('init',            ['Dernek_Post_Type',    'register']);
add_action('rest_api_init',   ['Dernek_REST_API',     'register_routes']);
add_action('wp_dashboard_setup', ['Dernek_Admin_Widget', 'add_widget']);
add_action('admin_menu',      'dernek_admin_menu');

function dernek_admin_menu() {
    add_options_page(
        'Dernek AI Senkronizasyonu',
        'AI Senkronizasyon',
        'manage_options',
        'dernek-sync',
        'dernek_settings_page'
    );
}

function dernek_settings_page() {
    if (!current_user_can('manage_options')) return;
    if (isset($_POST['dernek_save']) && check_admin_referer('dernek_settings')) {
        update_option('dernek_sheets_url', esc_url_raw($_POST['sheets_url'] ?? ''));
        update_option('dernek_api_secret',  sanitize_text_field($_POST['api_secret'] ?? ''));
        echo '<div class="notice notice-success"><p>Ayarlar kaydedildi.</p></div>';
    }
    $url    = get_option('dernek_sheets_url', '');
    $secret = get_option('dernek_api_secret', '');
    ?>
    <div class="wrap">
      <h1>Dernek AI Senkronizasyon Ayarlari</h1>
      <form method="post">
        <?php wp_nonce_field('dernek_settings'); ?>
        <table class="form-table">
          <tr>
            <th><label for="sheets_url">Google Sheets Web App URL</label></th>
            <td><input type="url" id="sheets_url" name="sheets_url" value="<?php echo esc_attr($url); ?>" class="regular-text" placeholder="https://script.google.com/macros/s/.../exec"></td>
          </tr>
          <tr>
            <th><label for="api_secret">API Secret Token</label></th>
            <td><input type="password" id="api_secret" name="api_secret" value="<?php echo esc_attr($secret); ?>" class="regular-text"></td>
          </tr>
        </table>
        <p class="submit"><input type="submit" name="dernek_save" class="button-primary" value="Kaydet"></p>
      </form>
      <hr>
      <h2>REST API Endpointleri</h2>
      <ul>
        <li><code>POST <?php echo esc_url(rest_url('dernek/v1/log')); ?></code> — Konusma logu ekle</li>
        <li><code>GET  <?php echo esc_url(rest_url('dernek/v1/projects')); ?></code> — Proje listesi</li>
        <li><code>POST <?php echo esc_url(rest_url('dernek/v1/deploy')); ?></code> — Deploy logu ekle</li>
      </ul>
    </div>
    <?php
}

register_activation_hook(__FILE__,   function() { Dernek_Post_Type::register(); flush_rewrite_rules(); });
register_deactivation_hook(__FILE__, function() { flush_rewrite_rules(); });
