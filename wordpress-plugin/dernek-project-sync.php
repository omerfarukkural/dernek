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
require_once DERNEK_SYNC_PATH . 'includes/class-social-scheduler.php';
require_once DERNEK_SYNC_PATH . 'includes/class-content-pipeline.php';
require_once DERNEK_SYNC_PATH . 'includes/class-notebooklm-bridge.php';
require_once DERNEK_SYNC_PATH . 'public/shortcode.php';

add_action('init',            ['Dernek_Post_Type',    'register']);
add_action('init',            ['Dernek_Social_Scheduler', 'register_cpt']);
add_action('rest_api_init',   ['Dernek_REST_API',     'register_routes']);
add_action('rest_api_init',   ['Dernek_Social_Scheduler', 'register_routes']);
add_action('rest_api_init',   ['Dernek_NotebookLM_Bridge', 'register_routes']);
add_action('wp_dashboard_setup', ['Dernek_Admin_Widget', 'add_widget']);
add_action('admin_menu',      'dernek_admin_menu');
add_action('dernek_publish_scheduled_posts', ['Dernek_Social_Scheduler', 'publish_due_posts']);

if (!wp_next_scheduled('dernek_publish_scheduled_posts')) {
    wp_schedule_event(time(), 'every_five_minutes', 'dernek_publish_scheduled_posts');
}

add_filter('cron_schedules', function($schedules) {
    $schedules['every_five_minutes'] = ['interval' => 300, 'display' => 'Her 5 Dakika'];
    return $schedules;
});

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
        update_option('dernek_sheets_url',        esc_url_raw($_POST['sheets_url'] ?? ''));
        update_option('dernek_api_secret',         sanitize_text_field($_POST['api_secret'] ?? ''));
        update_option('dernek_telegram_token',     sanitize_text_field($_POST['telegram_token'] ?? ''));
        update_option('dernek_telegram_chat_id',   sanitize_text_field($_POST['telegram_chat_id'] ?? ''));
        update_option('dernek_perplexity_api_key', sanitize_text_field($_POST['perplexity_key'] ?? ''));
        update_option('dernek_gemini_api_key',     sanitize_text_field($_POST['gemini_key'] ?? ''));
        update_option('dernek_claude_api_key',     sanitize_text_field($_POST['claude_key'] ?? ''));
        update_option('dernek_social_accounts',    wp_kses_post($_POST['social_accounts'] ?? '{}'));
        echo '<div class="notice notice-success"><p>Ayarlar kaydedildi.</p></div>';
    }
    $url              = get_option('dernek_sheets_url', '');
    $secret           = get_option('dernek_api_secret', '');
    $telegram_token   = get_option('dernek_telegram_token', '');
    $telegram_chat_id = get_option('dernek_telegram_chat_id', '');
    $perplexity_key   = get_option('dernek_perplexity_api_key', '');
    $gemini_key       = get_option('dernek_gemini_api_key', '');
    $claude_key       = get_option('dernek_claude_api_key', '');
    $social_accounts  = get_option('dernek_social_accounts', '{}');
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
        <h2>Telegram</h2>
        <table class="form-table">
          <tr>
            <th><label for="telegram_token">Telegram Bot Token</label></th>
            <td><input type="password" id="telegram_token" name="telegram_token" value="<?php echo esc_attr($telegram_token); ?>" class="regular-text" placeholder="1234567890:ABCdef..."></td>
          </tr>
          <tr>
            <th><label for="telegram_chat_id">Telegram Chat ID</label></th>
            <td><input type="text" id="telegram_chat_id" name="telegram_chat_id" value="<?php echo esc_attr($telegram_chat_id); ?>" class="regular-text"></td>
          </tr>
        </table>
        <h2>AI API Anahtarları</h2>
        <table class="form-table">
          <tr>
            <th><label for="perplexity_key">Perplexity API Key</label></th>
            <td><input type="password" id="perplexity_key" name="perplexity_key" value="<?php echo esc_attr($perplexity_key); ?>" class="regular-text"></td>
          </tr>
          <tr>
            <th><label for="gemini_key">Gemini API Key</label></th>
            <td><input type="password" id="gemini_key" name="gemini_key" value="<?php echo esc_attr($gemini_key); ?>" class="regular-text"></td>
          </tr>
          <tr>
            <th><label for="claude_key">Claude API Key</label></th>
            <td><input type="password" id="claude_key" name="claude_key" value="<?php echo esc_attr($claude_key); ?>" class="regular-text"></td>
          </tr>
        </table>
        <h2>Sosyal Medya Hesapları</h2>
        <table class="form-table">
          <tr>
            <th><label for="social_accounts">Hesap Yapılandırması (JSON)</label></th>
            <td>
              <textarea id="social_accounts" name="social_accounts" rows="8" class="large-text code"><?php echo esc_textarea($social_accounts); ?></textarea>
              <p class="description">JeSuspended hesap ID'lerini JSON formatında girin. Örnek: {"personal":{"threads":"id1"},"dernek":{"facebook":"id2"}}</p>
            </td>
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

// Royal MCP allowlist — bu satırlar olmadan Royal MCP bu seçenekleri yazamaz
add_filter('royal_mcp_writable_options', function($options) {
    $options[] = 'dernek_sheets_url';
    $options[] = 'dernek_api_secret';
    $options[] = 'dernek_telegram_token';
    $options[] = 'dernek_telegram_chat_id';
    $options[] = 'dernek_social_accounts';
    return $options;
});
