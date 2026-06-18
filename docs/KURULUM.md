# Dernek AI Takip Sistemi — Kurulum Rehberi

## 1. Google Sheets Kurulumu

### Apps Script Kodunu Yukleme
1. Google Drive'da yeni bir Google Sheets dosyasi olusturun.
2. Ust menudan **Uzantilar > Apps Script** seceneğine tiklayın.
3. Acilan editorde soldaki dosya listesine `Code.gs`, `Sheets.gs`, `Drive.gs`, `Menu.gs`, `CostTracker.gs` dosyalarini olusturun ve bu repodaki iceriklerini yapiştirin.
4. Kaydedin (Ctrl+S).

### Sheets Yapısını Olusturma
1. Apps Script editöründe `setupSheets` fonksiyonunu secin ve calistirin.
2. Izin ekranlarina onay verin. Bu adim gerekli sekmeli yapıyı otomatik olusturur.

### Web App Olarak Deploy Etme
1. Apps Script editöründe **Deploy > New Deployment** tiklayin.
2. Tur olarak **Web App** secin.
3. Asagidaki ayarlari yapın:
   - **Description:** v1.0
   - **Execute as:** Me
   - **Who has access:** Anyone
4. **Deploy** butonuna basin ve oluşan URL'yi kaydedin (örnek: `https://script.google.com/macros/s/XXXX/exec`).

### Script Properties Ayarlama
1. Apps Script editöründe **Project Settings > Script Properties** secin.
2. Asagidaki özellikleri ekleyin:
   - `WEBHOOK_SECRET` — Güçlü, rastgele bir token (örneğin: `my-secret-2024`)
   - `MONTHLY_BUDGET_USD` — Aylık bütçe limiti (örneğin: `10`)

---

## 2. Chrome Extension Kurulumu

1. Chrome tarayıcısında adres çubuğuna `chrome://extensions` yazın.
2. Sağ üstten **Developer mode** (Geliştirici modu) anahtarını açın.
3. **Load unpacked** (Paketlenmemişi yükle) butonuna tıklayın.
4. Bu repodaki `chrome-extension/` klasörünü seçin.
5. Uzantı yüklendikten sonra araç çubuğundaki AI simgesine tıklayın.
6. **Ayarlar** sekmesine geçin ve şunları doldurun:
   - **Apps Script Web App URL:** Yukarıda aldığınız deploy URL'si
   - **Webhook Secret Token:** `WEBHOOK_SECRET` için girdiğiniz değer
7. Kaydedin.

> **Not:** `icons/` klasöründe `icon16.png`, `icon48.png`, `icon128.png` dosyaları eksikse uzantı yüklenemez. `icons/README.txt` dosyasındaki talimatlara göre ikonları ekleyin.

---

## 3. Mac Local Proxy Kurulumu

### Kurulum
```bash
cd /Users/YOUR_USERNAME/dernek/local-proxy
npm install
cp .env.example .env
```

`.env` dosyasını düzenleyin:
```
PROXY_PORT=8080
APPS_SCRIPT_URL=https://script.google.com/macros/s/XXXX/exec
WEBHOOK_SECRET=my-secret-2024
DEFAULT_PROJECT=Genel
```

### Test
```bash
npm start
```

### Sistem Servisi Olarak Kurma (launchd)
1. `com.dernek.aiproxy.plist` dosyasındaki `YOUR_USERNAME` değerlerini gerçek kullanıcı adınızla değiştirin.
2. Dosyayı LaunchAgents klasörüne kopyalayın:
```bash
cp com.dernek.aiproxy.plist ~/Library/LaunchAgents/
launchctl load ~/Library/LaunchAgents/com.dernek.aiproxy.plist
```
3. Proxy'nin çalıştığını doğrulayın:
```bash
launchctl list | grep dernek
```

---

## 4. iOS Shortcut Kurulumu

### Manuel Kayıt Adımı
1. iPhone'da **Kısayollar** (Shortcuts) uygulamasını açın.
2. Yeni kısayol oluşturun ve şu adımları ekleyin:
   - **Metni Sor** — kullanıcıdan proje adı ve prompt özeti isteyin.
   - **URL** — Apps Script Web App URL'sini girin.
   - **URL İçeriğini Al** — Method: POST, Request Body: JSON olarak ayarlayın.
   - JSON gövdesine şunu ekleyin:
     ```json
     {
       "token": "YOUR_WEBHOOK_SECRET",
       "type": "conversation",
       "device": "ios-shortcut",
       "tool": "claude",
       "project": "Kısayol Girdisi",
       "prompt_summary": "Kısayol Girdisi",
       "response_summary": "",
       "tokens_used": 0
     }
     ```
3. Kısayolu ana ekrana ekleyin.

### Apps Script URL'sini Shortcut'a Girme
- Kısayoldaki URL adımında tam deploy URL'sini kullanın (örnek: `https://script.google.com/macros/s/XXXX/exec`).
- `YOUR_WEBHOOK_SECRET` yerine gerçek tokenı yazın.

---

## 5. WordPress Eklentisi Kurulumu

### Turhost cPanel FTP ile Yükleme
1. Turhost cPanel'e giriş yapın.
2. **File Manager** veya FTP istemcisi (FileZilla) ile bağlanın.
3. `wp-content/plugins/` dizinine gidin.
4. `wordpress-plugin/` klasörünü `dernek-project-sync/` adıyla yükleyin. Klasör yapısı şöyle olmalı:
   ```
   wp-content/plugins/dernek-project-sync/
   ├── dernek-project-sync.php
   ├── includes/
   │   ├── class-post-type.php
   │   ├── class-rest-api.php
   │   └── class-admin-widget.php
   └── public/
       └── shortcode.php
   ```

### wp-admin'den Aktive Etme
1. WordPress yönetim paneline giriş yapın.
2. **Eklentiler > Yüklü Eklentiler** sayfasına gidin.
3. **Dernek AI Proje Senkronizasyonu** eklentisini bulun ve **Etkinleştir** butonuna tıklayın.

### Ayarlar Sayfası
1. **Ayarlar > AI Senkronizasyon** menüsüne gidin.
2. Şu alanları doldurun:
   - **Google Sheets Web App URL:** Deploy URL'niz
   - **API Secret Token:** `WEBHOOK_SECRET` değeriniz
3. **Kaydet** butonuna tıklayın.

---

## 6. n8n Workflow Kurulumu

### n8n.bitebimuv.org Üzerinden Import
1. `https://n8n.bitebimuv.org` adresine giriş yapın.
2. Sol menüden **Workflows > New Workflow** secin.
3. Sağ üstten **Import from File** seçeneğine tıklayın.
4. `n8n-workflows/` klasöründeki JSON dosyalarını sırayla içe aktarın:
   - `sheets-to-wordpress.json`
   - `github-to-gcp-deploy.json`
   - `deploy-log.json`

### Credential Ayarları
Her workflow için gerekli credential'ları ayarlayın:

**Google Sheets credential:**
1. n8n'de **Credentials > New** seçin.
2. Google Sheets OAuth2 credential oluşturun.
3. Workflow'daki Google Sheets node'larına bu credential'ı atayın.

**Ortam değişkenleri:**
n8n Settings > Environment Variables bölümüne ekleyin:
- `SHEETS_ID` — Google Sheets dosya ID'si (URL'den alınır)
- `APPS_SCRIPT_URL` — Web App deploy URL'si
- `WEBHOOK_SECRET` — Secret token
- `GCP_PROJECT_ID` — Google Cloud proje ID'si (deploy workflow için)
- `GCP_TRIGGER_ID` — Cloud Build trigger ID'si

---

## 7. NotebookLM Entegrasyonu

### Drive Klasörünü Kaynak Olarak Ekleme
1. [NotebookLM](https://notebooklm.google.com) adresine gidin.
2. Yeni bir notebook oluşturun veya mevcut birine girin.
3. **Add Source > Google Drive** seçeneğine tıklayın.
4. `AI-Projeler/` ana klasörünü veya ilgili proje alt klasörünü seçin.
5. NotebookLM, Drive'a kaydedilen konuşma dosyalarını (.md) otomatik olarak indeksler.

> **İpucu:** Her proje için ayrı bir NotebookLM notebook oluşturarak proje bazında sorgulama yapabilirsiniz. Drive klasörüne yeni dosyalar eklendikçe NotebookLM'deki kaynakları **Refresh** ederek güncel tutun.
