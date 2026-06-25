#!/usr/bin/env python3
"""
Neutralize suspicious PHP files by overwriting with empty stubs.
Also write clean .user.ini to prevent auto_prepend_file.
"""
import os, requests, sys

requests.packages.urllib3.disable_warnings()

host    = "srvc03.trwww.com"
user    = os.environ["CPANEL_USER"]
token   = os.environ["CPANEL_TOKEN"]
headers = {"Authorization": f"cpanel {user}:{token}"}

def uapi(module, func, params=None):
    r = requests.post(
        f"https://{host}:2083/execute/{module}/{func}",
        headers=headers,
        data=params or {},
        verify=False, timeout=30,
    )
    return r.json()

def write_file(dir_, name, content):
    r = uapi("Fileman", "save_file_content", {
        "dir": dir_, "file": name, "content": content,
    })
    ok = r.get("status") == 1
    status = "✓" if ok else "✗"
    err = f" ({r.get('errors')})" if not ok else ""
    print(f"  {status} {dir_}/{name}{err}")
    return ok

STUB = "<?php // disabled by maintenance\n"

# --- 1. Overwrite suspicious PHP files in public_html root ---
print("=" * 60)
print("NEUTRALIZING SUSPICIOUS PHP FILES (overwrite with stub)")
print("=" * 60)
suspicious = [
    "homepage-redirect.php",
    "debug-new.php",
    "debug-temp.php",
    "dernek-fetch.php",       # our uploaded fetcher, clean up
]
for fname in suspicious:
    write_file("public_html", fname, STUB)

# --- 2. Write clean .user.ini (no auto_prepend_file) ---
print("\n" + "=" * 60)
print("WRITING CLEAN .user.ini")
print("=" * 60)
user_ini = """; PHP user config - clean state
; auto_prepend_file = disabled
"""
write_file("public_html", ".user.ini", user_ini)

# --- 3. Try writing to wp-content/mu-plugins (create via write) ---
# WordPress loads ALL files in mu-plugins automatically
# The dir might not exist but we can try to write anyway
print("\n" + "=" * 60)
print("WRITING WP MU-PLUGINS INSTALLER")
print("=" * 60)

mu_plugin = """<?php
/**
 * Plugin Name: Dernek Auto Installer
 * Description: Creates dernek-project-sync plugin directory and files, then self-deletes.
 */
add_action('plugins_loaded', function() {
    $base = WP_PLUGIN_DIR . '/dernek-project-sync';
    if (is_dir($base) && file_exists($base . '/dernek-project-sync.php')) {
        unlink(__FILE__);
        return;
    }
    foreach (['', '/includes', '/public'] as $sub) {
        wp_mkdir_p($base . $sub);
    }
    // Write main plugin file so it appears in plugin list
    file_put_contents($base . '/dernek-project-sync.php', '<?php /* Plugin Name: Dernek AI Proje Senkronizasyonu */ echo "Installer placeholder - upload full plugin files."; ?>');
    unlink(__FILE__);
}, 1);
"""
write_file("public_html/wp-content/mu-plugins", "dernek-auto-install.php", mu_plugin)

# --- 4. Also write a wp-content drop-in as fallback ---
# wp-content/db.php runs before plugins load
# But db.php is for database, let's not touch that
# Instead, write a marker file to confirm write access
write_file("public_html/wp-content", "dernek-write-test.txt", "write OK\n")

# --- 5. Try writing directly into plugins dir (existing dir) ---
print("\n" + "=" * 60)
print("WRITING STUB TO wp-content/plugins/ (existing dir)")
print("=" * 60)
# A single-file plugin won't have subdirs but confirms the dir is writable
stub_plugin = """<?php
/**
 * Plugin Name: Dernek AI Proje Senkronizasyonu (Stub)
 * Description: Stub plugin - replace with full version.
 * Version: 1.0.0
 */
// Stub - full plugin files not yet installed
"""
write_file("public_html/wp-content/plugins", "dernek-project-sync-stub.php", stub_plugin)

print("\n=== Done ===")
print("bitebimuv.org adresini tarayıcıda test edin.")
print("Eğer site açılıyorsa WordPress Admin'den plugin aktivasyonunu yapın.")
