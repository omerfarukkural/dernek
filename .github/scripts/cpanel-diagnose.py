#!/usr/bin/env python3
"""Read .htaccess and error_log via cPanel UAPI, then fix .htaccess."""
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

# --- 1. Read .htaccess ---
print("=" * 60)
print(".HTACCESS CONTENT")
print("=" * 60)
r = uapi("Fileman", "show_file_content", {"dir": "public_html", "file": ".htaccess"})
if r.get("status") == 1:
    print(r["data"]["content"])
else:
    print(f"Error: {r.get('errors')}")

# --- 2. Read error_log (last 50 lines) ---
print("\n" + "=" * 60)
print("ERROR LOG (last 50 lines)")
print("=" * 60)
for logname in ["error_log", ".php_error_log", "php_error.log"]:
    r2 = uapi("Fileman", "show_file_content", {"dir": "public_html", "file": logname})
    if r2.get("status") == 1:
        content = r2["data"]["content"]
        lines = content.strip().split("\n")
        print(f"File: {logname}")
        print("\n".join(lines[-50:]))
        break
else:
    print("No error log found in public_html")

# --- 3. Fix .htaccess with clean WordPress rules ---
print("\n" + "=" * 60)
print("WRITING CLEAN .htaccess")
print("=" * 60)
clean_htaccess = """# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
"""
# First backup
uapi("Fileman", "save_file_content", {
    "dir": "public_html", "file": ".htaccess_claude_backup",
    "content": r.get("data", {}).get("content", "# backup empty") if r.get("status") == 1 else "# backup failed"
})
# Write clean version
r3 = uapi("Fileman", "save_file_content", {
    "dir": "public_html", "file": ".htaccess",
    "content": clean_htaccess,
})
if r3.get("status") == 1:
    print("Clean .htaccess written successfully")
else:
    print(f"Failed to write .htaccess: {r3.get('errors')}")

# --- 4. List public_html top-level ---
print("\n" + "=" * 60)
print("public_html CONTENTS")
print("=" * 60)
r4 = uapi("Fileman", "list_files", {"dir": "public_html"})
if r4.get("status") == 1:
    for f in r4.get("data", []):
        ftype = "DIR " if f.get("type") == "dir" else "FILE"
        print(f"  {ftype} {f.get('file','?')} ({f.get('size',0)} bytes)")
else:
    print(f"Error: {r4.get('errors')}")

# --- 5. List plugins directory ---
print("\n" + "=" * 60)
print("PLUGINS DIRECTORY")
print("=" * 60)
r5 = uapi("Fileman", "list_files", {"dir": "public_html/wp-content/plugins"})
if r5.get("status") == 1:
    for f in r5.get("data", []):
        print(f"  {f.get('type','')} {f.get('file','?')}")
else:
    print(f"Error: {r5.get('errors')}")
