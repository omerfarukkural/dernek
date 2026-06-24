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
add_action('rest_api_init',   function() { Dernek_NotebookLM_Bridge::get_instance(); });
add_action('plugins_loaded',     function() { Dernek_Content_Pipeline::get_instance(); });
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
        update_option('dernek_openai_api_key',     sanitize_text_field($_POST['openai_key'] ?? ''));
        update_option('dernek_social_accounts',    wp_unslash($_POST['social_accounts'] ?? '{}'));
        echo '<div class="notice notice-success"><p>✅ Ayarlar kaydedildi.</p></div>';
    }
    $url              = get_option('dernek_sheets_url', '');
    $secret           = get_option('dernek_api_secret', '');
    $telegram_token   = get_option('dernek_telegram_token', '');
    $telegram_chat_id = get_option('dernek_telegram_chat_id', '');
    $perplexity_key   = get_option('dernek_perplexity_api_key', '');
    $gemini_key       = get_option('dernek_gemini_api_key', '');
    $claude_key       = get_option('dernek_claude_api_key', '');
    $openai_key       = get_option('dernek_openai_api_key', '');
    $social_accounts  = get_option('dernek_social_accounts', '');

    // Varsayılan JSON şablonu
    if (!$social_accounts) {
        $social_accounts = json_encode([
            'personal' => [
                'threads'   => ['user_id' => '', 'access_token' => ''],
                'facebook'  => ['page_id' => '', 'access_token' => ''],
                'instagram' => ['user_id' => '', 'access_token' => ''],
                'twitter'   => ['api_key' => '', 'api_secret' => '', 'access_token' => '', 'access_token_secret' => ''],
                'linkedin'  => ['person_urn' => '', 'access_token' => ''],
                'bluesky'   => ['handle' => '', 'password' => ''],
                'pinterest' => ['access_token' => '', 'board_id' => ''],
                'tiktok'    => ['access_token' => ''],
                'youtube'   => ['access_token' => '', 'channel_id' => ''],
            ],
            'dernek' => [
                'threads'   => ['user_id' => '', 'access_token' => ''],
                'facebook'  => ['page_id' => '', 'access_token' => ''],
                'instagram' => ['user_id' => '', 'access_token' => ''],
                'linkedin'  => ['person_urn' => '', 'access_token' => ''],
                'bluesky'   => ['handle' => '', 'password' => ''],
                'youtube'   => ['access_token' => '', 'channel_id' => ''],
            ],
            'viral' => [
                'threads'   => ['user_id' => '', 'access_token' => ''],
                'instagram' => ['user_id' => '', 'access_token' => ''],
                'tiktok'    => ['access_token' => ''],
                'twitter'   => ['api_key' => '', 'api_secret' => '', 'access_token' => '', 'access_token_secret' => ''],
                'bluesky'   => ['handle' => '', 'password' => ''],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    ?>
    <div class="wrap">
      <h1>🤖 Dernek AI Senkronizasyon Ayarları</h1>

      <?php
      // Webhook durumu kontrol
      $tg_token = get_option('dernek_telegram_token','');
      if ($tg_token) {
          $wh = wp_remote_get("https://api.telegram.org/bot$tg_token/getWebhookInfo");
          if (!is_wp_error($wh)) {
              $wh_data = json_decode(wp_remote_retrieve_body($wh), true);
              $wh_url  = $wh_data['result']['url'] ?? '';
              if ($wh_url) {
                  echo '<div class="notice notice-info inline"><p>✅ Telegram Webhook aktif: <code>' . esc_html($wh_url) . '</code></p></div>';
              } else {
                  echo '<div class="notice notice-warning inline"><p>⚠️ Telegram Webhook ayarlı değil!</p></div>';
              }
          }
      }
      ?>

      <form method="post">
        <?php wp_nonce_field('dernek_settings'); ?>

        <h2>🔗 Temel Ayarlar</h2>
        <table class="form-table">
          <tr>
            <th><label for="sheets_url">Google Sheets Web App URL</label></th>
            <td><input type="url" id="sheets_url" name="sheets_url" value="<?php echo esc_attr($url); ?>" class="large-text" placeholder="https://script.google.com/macros/s/.../exec"></td>
          </tr>
          <tr>
            <th><label for="api_secret">API Secret Token</label></th>
            <td><input type="password" id="api_secret" name="api_secret" value="<?php echo esc_attr($secret); ?>" class="regular-text"></td>
          </tr>
        </table>

        <h2>📱 Telegram</h2>
        <table class="form-table">
          <tr>
            <th><label for="telegram_token">Bot Token</label></th>
            <td>
              <input type="password" id="telegram_token" name="telegram_token" value="<?php echo esc_attr($telegram_token); ?>" class="regular-text" placeholder="1234567890:ABCdef...">
              <p class="description">BotFather'dan alınan token</p>
            </td>
          </tr>
          <tr>
            <th><label for="telegram_chat_id">Chat ID (Sizin ID'niz)</label></th>
            <td>
              <input type="text" id="telegram_chat_id" name="telegram_chat_id" value="<?php echo esc_attr($telegram_chat_id); ?>" class="regular-text">
              <p class="description">Onay mesajlarının gönderileceği kişisel Telegram ID'niz</p>
            </td>
          </tr>
        </table>

        <h2>🤖 AI API Anahtarları</h2>
        <table class="form-table">
          <tr>
            <th><label for="perplexity_key">Perplexity API Key</label></th>
            <td><input type="password" id="perplexity_key" name="perplexity_key" value="<?php echo esc_attr($perplexity_key); ?>" class="regular-text" placeholder="pplx-..."></td>
          </tr>
          <tr>
            <th><label for="gemini_key">Gemini API Key</label></th>
            <td><input type="password" id="gemini_key" name="gemini_key" value="<?php echo esc_attr($gemini_key); ?>" class="regular-text" placeholder="AIza..."></td>
          </tr>
          <tr>
            <th><label for="claude_key">Claude (Anthropic) API Key</label></th>
            <td><input type="password" id="claude_key" name="claude_key" value="<?php echo esc_attr($claude_key); ?>" class="regular-text" placeholder="sk-ant-..."></td>
          </tr>
          <tr>
            <th><label for="openai_key">OpenAI API Key</label></th>
            <td><input type="password" id="openai_key" name="openai_key" value="<?php echo esc_attr($openai_key); ?>" class="regular-text" placeholder="sk-proj-..."></td>
          </tr>
        </table>

        <h2>🌐 Sosyal Medya Hesap Yapılandırması</h2>
        <p>Desteklenen platformlar: <strong>Threads · Facebook · Instagram · Twitter/X · LinkedIn · TikTok · YouTube · Pinterest · Bluesky</strong></p>
        <p>Her hesap tipi (personal/dernek/viral) için platform bazlı token'ları JSON olarak girin:</p>
        <table class="form-table">
          <tr>
            <th><label for="social_accounts">Hesap JSON</label></th>
            <td>
              <textarea id="social_accounts" name="social_accounts" rows="30" class="large-text code" style="font-family:monospace;font-size:12px;"><?php echo esc_textarea($social_accounts); ?></textarea>
              <p class="description">
                <strong>Twitter:</strong> api_key + api_secret + access_token + access_token_secret<br>
                <strong>LinkedIn:</strong> person_urn (urn:li:person:XXXXX) + access_token<br>
                <strong>Bluesky:</strong> handle (user.bsky.social) + password (App Password)<br>
                <strong>Pinterest:</strong> access_token + board_id<br>
                <strong>TikTok/YouTube/IG/FB/Threads:</strong> user_id/page_id + access_token
              </p>
            </td>
          </tr>
        </table>

        <p class="submit">
          <input type="submit" name="dernek_save" class="button-primary" value="💾 Ayarları Kaydet">
        </p>
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
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('dernek_publish_scheduled_posts');
    flush_rewrite_rules();
});

// Royal MCP allowlist — bu satırlar olmadan Royal MCP bu seçenekleri yazamaz
add_filter('royal_mcp_writable_options', function($options) {
    $options[] = 'dernek_sheets_url';
    $options[] = 'dernek_api_secret';
    $options[] = 'dernek_telegram_token';
    $options[] = 'dernek_telegram_chat_id';
    $options[] = 'dernek_social_accounts';
    return $options;
});
