# AI Takip Sistemi — Kurulum Kilavuzu

## Gereksinimler

- Node.js 18 veya uzeri (local proxy icin)
- Google Chrome tarayici (extension icin)
- Google hesabi (Sheets ve Drive icin)
- WordPress 6.0+ sitesi (opsiyonel, bitebimuv.org)
- n8n instance (opsiyonel, n8n.bitebimuv.org)

---

## Adim 1: Google Sheets Kurulumu

1. **Yeni bir Google Sheets tablosu olusturun** (ornegin: "AI Proje Takip")
2. **Apps Script'i acin:** Uzantilar > Apps Script
3. **Dosyalari yapiştirin:**
   - `Sheets.gs`, `Drive.gs`, `Menu.gs`, `CostTracker.gs` iceriklerini ilgili `.gs` dosyalarina kopyalayin
   - Varsa `Code.gs` icerigini silin ya da bos birakin
4. **Kaydedin** (Ctrl+S) ve **Calistirin:** `setupSheets` fonksiyonunu secip calistirin — izin isteyin ve onaylayin
5. **Web App olarak deploy edin:**
   - Sagda "Deploy > Yeni deployment" tiklayin
   - Tur: "Web app"
   - Calistir: "Ben" (Me)
   - Erisim: "Herkes" (Anyone)
   - Deploy tiklayin — URL'yi kopyalayin
6. **Script Properties ayarlayin:** Proje Ayarlari > Script Properties:
   - `WEBHOOK_SECRET` = guclu bir token (ornegin: `dernek-secret-2024-xyz`)
   - `MONTHLY_BUDGET_USD` = `10` (veya istediginiz tutar)

---

## Adim 2: Chrome Extension Kurulumu

1. Chrome'da `chrome://extensions` adresine gidin
2. Sag ust kosede **"Gelistirici modu"** acin
3. **"Paketsiz yukleme"** butonuna basin
4. `dernek/chrome-extension/` klasorunu secin
5. Extension yuklendi — ikon toolbar'da gorunmeli
6. **Extension'i yapilandirin:**
   - Ikon'a sag tikla > "Popup'u ac" ya da normal tikla
   - "Ayarlar" sekmesine gec
   - Apps Script Web App URL'sini yapistir
   - Webhook Secret Token'i gir
   - "Kaydet" tikla
7. `claude.ai`, `gemini.google.com` veya `perplexity.ai` acin — badge "aktif" gostermeli

---

## Adim 3: Mac Local Proxy

1. **Dosyalari hazirlayin:**
   ```bash
   cd /Users/YOUR_USERNAME/dernek/local-proxy
   npm install
   ```
2. **.env dosyasi olusturun:**
   ```bash
   cp .env.example .env
   ```
   Ardından `.env` dosyasini duzenleyin:
   ```
   PROXY_PORT=8080
   APPS_SCRIPT_URL=https://script.google.com/macros/s/SCRIPT_ID/exec
   WEBHOOK_SECRET=dernek-secret-2024-xyz
   DEFAULT_PROJECT=Genel
   ```
3. **Test calistirma:**
   ```bash
   node proxy-server.js
   ```
   Cikti: `[AI Proxy] Calistirildi: http://127.0.0.1:8080`
4. **Launchd ile otomatik baslatma (Mac):**
   ```bash
   # com.dernek.aiproxy.plist icerisindeki YOUR_USERNAME degerini degistirin
   cp com.dernek.aiproxy.plist ~/Library/LaunchAgents/
   launchctl load ~/Library/LaunchAgents/com.dernek.aiproxy.plist
   ```
5. **IntelliJ/IDE yapilandirmasi:** HTTP isteklerinde proxy olarak `127.0.0.1:8080` kullanin, `X-Target-Host: api.anthropic.com` header'i ekleyin

---

## Adim 4: iOS Shortcut (Manuel Adimlar)

1. Apps Script Web App URL'sini kopyalayin
2. iPhone'da **Kisayollar** uygulamasini acin
3. **Yeni Kisayol** olusturun
4. **"URL icerigini al"** veya **"Web istegi"** eylemi ekleyin:
   - URL: `https://script.google.com/macros/s/SCRIPT_ID/exec`
   - Yontem: POST
   - Govde: JSON
   - Icerik:
     ```json
     {
       "token": "dernek-secret-2024-xyz",
       "type": "conversation",
       "device": "iphone",
       "tool": "claude",
       "project": "Genel",
       "prompt_summary": "Manuel iOS logu",
       "response_summary": "",
       "tokens_used": 0
     }
     ```
5. Kisayolu **Ana Ekrana** ekleyin

---

## Adim 5: WordPress Eklentisi

1. **Eklentiyi yukleyin:**
   - FTP veya cPanel Dosya Yoneticisi ile `dernek/wordpress-plugin/` klasorunu
     `wp-content/plugins/dernek-project-sync/` olarak kopyalayin
2. **wp-admin'de aktive edin:**
   - Eklentiler > "Dernek AI Proje Senkronizasyonu" > Etkinlestir
3. **Ayarlari girin:**
   - Ayarlar > AI Senkronizasyon
   - Google Sheets Web App URL'sini girin
   - API Secret Token'i girin (Sheets'tekiyle ayni)
   - Kaydet

---

## Adim 6: n8n Workflow'lari

1. **n8n.bitebimuv.org** adresine giris yapin
2. **Workflow iceri aktar:**
   - Sol menu > Workflows > Import from file
   - `n8n-workflows/sheets-to-wordpress.json` secin
   - Ayni islemi diger iki JSON icin tekrarlayin
3. **Credential ayarlayin:**
   - `Google Sheets`: Google hesabinizla OAuth baglantin
   - `WordPress Basic Auth`: WP kullanici adi ve uygulama sifresi
   - `GCP Bearer Token`: Cloud Build icin service account token
4. **Environment variable'lari ayarlayin** (n8n Settings > Variables):
   - `SHEETS_ID`: Google Sheets dosya ID'si (URL'deki uzun string)
   - `WP_URL`: `https://bitebimuv.org`
   - `WEBHOOK_SECRET`: ayni secret token
   - `GCP_PROJECT_ID`: GCP proje adi
   - `CLOUD_BUILD_TRIGGER_ID`: Cloud Build trigger ID

---

## Adim 7: NotebookLM Entegrasyonu

1. **Google Drive**'da `AI-Projeler` klasorunu acin (Apps Script otomatik olusturur)
2. **NotebookLM**'e gidin: `notebooklm.google.com`
3. **Yeni notebook olusturun:** proje adiyla
4. **Kaynak ekleyin:**
   - "Kaynak ekle" > "Google Drive"
   - `AI-Projeler/Claude/PROJE_ADI/` klasorunu secin
5. Drive'a her yeni MD dosyasi eklendiginde NotebookLM otomatik guncellenir

---

## Test

1. `claude.ai`'da herhangi bir konusma yapin
2. Extension popup'u acin, proje secin, "Simdi Kaydet" tiklayin
3. **Sheets'te** "Konusma Gecmisi" sayfasinda yeni satir gorunmeli
4. **Drive'da** `AI-Projeler/Claude/PROJE/API_Ciktilari/` klasorunde `.md` dosyasi olusturmali
5. **WordPress'te** AI Projeleri post type'inda entry gorunmeli

---

## Sorun Giderme

### Chrome Extension Calistirmiyor
- `chrome://extensions` > extension'in "Hatalar" butonuna bakin
- Popup'ta "Desteksiz sayfa" gosteriyor: Claude/Gemini/Perplexity sayfasinda olmalisiniz
- Console'da `[AI Takipci]` ile baslayan log satirlarina bakin (F12)

### Proxy Baglanti Hatasi
```bash
# Log dosyasini kontrol edin
tail -f ~/Library/Logs/dernek-ai-proxy-error.log

# Servisin calisip calismadigini kontrol edin
launchctl list | grep dernek

# Manuel restart
launchctl unload ~/Library/LaunchAgents/com.dernek.aiproxy.plist
launchctl load ~/Library/LaunchAgents/com.dernek.aiproxy.plist
```

### WordPress 401 Hatasi
- `Ayarlar > AI Senkronizasyon` sayfasinda token'in dogru girildigini kontrol edin
- REST API'nin aktif oldugunu test edin: `curl https://bitebimuv.org/wp-json/dernek/v1/projects`
- `.htaccess`'te Authorization header'inin gecmesini saglayin:
  ```apache
  SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
  ```
