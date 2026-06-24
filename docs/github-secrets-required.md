# GitHub Secrets Gereksinimleri

Bootstrap deploy workflow icin su secrets ayarlanmali:

## Repository Secrets
https://github.com/omerfarukkural/dernek/settings/secrets/actions

| Secret Adi    | Deger                          | Nereden |
|---------------|-------------------------------|----------|
| CPANEL_USER   | bitebim                        | FTP kullanici adindan |
| CPANEL_TOKEN  | cPanel API Token               | cPanel > Security > Manage API Tokens |

## cPanel API Token Olusturma
1. https://srvc03.trwww.com:2083 giris yap
2. Security > Manage API Tokens
3. "Create" tikla, isim: github-deploy
4. Tokeni kopyala, GitHub secret olarak ekle

## WordPress Eklentisini Devre Disi Birakmak (503 duzeltme)
1. cPanel > File Manager
2. public_html/wp-content/plugins/
3. dernek-project-sync klasorunu yeniden adlandir: dernek-project-sync_DISABLED
4. WordPress acilir
