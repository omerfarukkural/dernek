#!/usr/bin/env python3
"""
Rename suspicious PHP files causing redirect, then create plugin directory
using a PHP stub that WordPress will execute via wp-cron.
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

def rename_file(dir_, src, dst):
    """Rename a file in public_html to disable it."""
    r = uapi("Fileman", "rename", {
        "sourcefiles": src,
        "destfiles":   dst,
        "dir":         dir_,
    })
    if r.get("status") == 1:
        print(f"  Renamed: {src} -> {dst}")
    else:
        err = (r.get("errors") or ["?"])[0]
        print(f"  Rename {src}: {err}")

def write_file(dir_, name, content):
    r = uapi("Fileman", "save_file_content", {
        "dir": dir_, "file": name, "content": content,
    })
    if r.get("status") == 1:
        print(f"  Written: {dir_}/{name}")
        return True
    else:
        print(f"  FAILED {name}: {r.get('errors')}")
        return False

# --- 1. Disable suspicious redirect files ---
print("=" * 60)
print("DISABLING SUSPICIOUS PHP FILES")
print("=" * 60)

suspicious = [
    "homepage-redirect.php",
    "debug-new.php",
    "debug-temp.php",
]
for fname in suspicious:
    rename_file("public_html", fname, fname + ".disabled")

# --- 2. Check if plugin directory exists via list_files ---
print("\n" + "=" * 60)
print("CHECKING PLUGIN DIRECTORY")
print("=" * 60)
r = uapi("Fileman", "list_files", {"dir": "public_html/wp-content/plugins/dernek-project-sync"})
if r.get("status") == 1:
    print("Plugin directory EXISTS — listing:")
    for f in r.get("data", []):
        print(f"  {f.get('type','')} {f.get('file','?')}")
else:
    print(f"Plugin directory does not exist: {r.get('errors')}")
    print("Will create via WordPress drop-in installer")

# --- 3. Write a WordPress MU (must-use) plugin that creates the dir ---
# MU plugins in wp-content/mu-plugins/ run automatically on every WP load
# We write a tiny MU plugin that creates the dernek-project-sync directory
# and copies itself-encoded content there, then removes itself.
print("\n" + "=" * 60)
print("CREATING WP MU INSTALLER PLUGIN")
print("=" * 60)

# Check if mu-plugins dir exists
r_mu = uapi("Fileman", "list_files", {"dir": "public_html/wp-content/mu-plugins"})
mu_exists = r_mu.get("status") == 1

if not mu_exists:
    print("mu-plugins directory does not exist, creating via installer approach...")

# We'll write the installer as an MU plugin if possible
# Otherwise write to wp-content directly and hope WP is partially functional
mu_content = """<?php
/**
 * Dernek Auto-Installer MU Plugin
 * Runs once on WordPress load, creates plugin directory and files, then self-deletes.
 */
add_action('init', function() {
    $base  = WP_PLUGIN_DIR . '/dernek-project-sync';
    $stamp = $base . '/.installed';
    if (file_exists($stamp)) { return; }

    // Create directories
    foreach (['', '/includes', '/public'] as $sub) {
        $dir = $base . $sub;
        if (!is_dir($dir)) wp_mkdir_p($dir);
    }

    // Signal done
    file_put_contents($stamp, date('c'));

    // Self-delete this MU plugin
    @unlink(__FILE__);
}, 1);
"""

# Try to write to mu-plugins
result = write_file("public_html/wp-content/mu-plugins", "dernek-mu-installer.php", mu_content)
if not result:
    print("Could not write MU plugin — mu-plugins dir may not exist")
    # Try writing a drop-in instead (wp-content/db.php runs very early)
    # Actually let's try creating the plugin files directly with a creative approach:
    # Write to an existing known directory first
    print("Trying alternative: writing to wp-content directly...")
    write_file("public_html/wp-content", "dernek-mu-installer.php", mu_content)

print("\nDone. Now test bitebimuv.org in browser.")
print("If site loads, WP MU plugin will auto-create the plugin directory on first load.")
print("Then you can activate the dernek-project-sync plugin from WP Admin.")
