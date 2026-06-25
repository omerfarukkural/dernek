#!/usr/bin/env python3
"""Upload PHP fetcher to cPanel, then execute full installer via HTTP."""
import os, requests, sys, time

requests.packages.urllib3.disable_warnings()

host    = "srvc03.trwww.com"
user    = os.environ["CPANEL_USER"]
token   = os.environ["CPANEL_TOKEN"]
headers = {"Authorization": f"cpanel {user}:{token}"}

RAW_URL = "https://raw.githubusercontent.com/omerfarukkural/dernek/main/wordpress-plugin/dernek-installer.php"

# Tiny PHP fetcher — fetches full installer from GitHub raw URL then runs it
# Uses file_get_contents with allow_url_fopen or falls back to curl
fetcher_lines = [
    "<?php",
    f"$u='{RAW_URL}';",
    "$c=@file_get_contents($u);",
    "if(!$c){$c=shell_exec('curl -fsSL '.$u);}",
    "if(!$c){die('Cannot fetch installer');}",
    "file_put_contents(__DIR__.'/dernek-inst-full.php',$c);",
    "header('Location: /dernek-inst-full.php?run=1');",
]
fetcher = "\n".join(fetcher_lines) + "\n"

print(f"Fetcher size: {len(fetcher)} bytes")
print("Uploading tiny fetcher to public_html...")

r = requests.post(
    f"https://{host}:2083/execute/Fileman/save_file_content",
    headers=headers,
    data={
        "dir":     "public_html",
        "file":    "dernek-fetch.php",
        "content": fetcher,
    },
    verify=False, timeout=30,
)
j = r.json()
if j.get("status") != 1:
    print(f"Upload failed: {j.get('errors')}")
    sys.exit(1)
print("Fetcher uploaded to public_html/dernek-fetch.php")

time.sleep(3)

# Hit the fetcher — it redirects to full installer
print("Executing fetcher (fetches installer from GitHub + runs it)...")
sess = requests.Session()
r2 = sess.get(
    "https://bitebimuv.org/dernek-fetch.php",
    timeout=120, verify=True, allow_redirects=True,
)
print(f"HTTP {r2.status_code}, final URL: {r2.url}")
output = r2.text[:5000]
print(output)

if "SUCCESS" in output:
    print("\nPlugin installed successfully!")
    sys.exit(0)

# WordPress 503/500 might block execution — try hitting full installer directly
if r2.status_code in (301, 302, 200) or "inst-full" not in r2.url:
    print("Trying direct URL to full installer...")
    r3 = sess.get(
        "https://bitebimuv.org/dernek-inst-full.php?run=1",
        timeout=120, verify=True,
    )
    print(f"HTTP {r3.status_code}")
    output3 = r3.text[:5000]
    print(output3)
    if "SUCCESS" in output3:
        print("\nPlugin installed successfully!")
        sys.exit(0)

print("\nUnexpected result — see output above")
sys.exit(1)
