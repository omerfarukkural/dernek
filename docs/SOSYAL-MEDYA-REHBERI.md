# Sosyal Medya Büyüme Motoru — Kurulum Rehberi

## Genel Bakış

Bu sistem üç hesap stratejisiyle çalışır:

| Hesap | Açıklama | Ton |
|-------|----------|-----|
| **Personal** | Ömer Faruk Kural kişisel hesabı | Samimi, deneyim odaklı |
| **Dernek** | Kurumsal dernek hesabı | Güvenilir, bilgilendirici |
| **Viral** | Büyüme odaklı influencer hesabı | Hook, trend, CTA |

### İçerik Akışı

```
[Konu Gir]
    │
    ▼
Perplexity → Güncel araştırma
    │
    ▼
Gemini → Platform'a özel içerik üret
    │
    ▼
Claude → Son rötuş + ton uyumu
    │
    ▼
WordPress → social_post CPT'ye kaydet
    │
    ▼
Telegram → Onay mesajı gönder (✅/❌/✏️/⏰)
    │
    ├─ Onaylanırsa → Anında veya zamanlı yayın
    └─ Reddedilirse → Taslak olarak bekle
         │
         ▼
Google Sheets → Tüm işlemler loglanır
Google Drive → Araştırma .md olarak kaydedilir
NotebookLM → Drive klasöründen bağlam çeker
```

---

## 1. Telegram Bot Kurulumu

### Bot Oluşturma
1. Telegram'da `@BotFather`'a mesaj at
2. `/newbot` komutunu gönder
3. Bot adı: `DernekAI Bot`
4. Kullanıcı adı: `dernek_ai_bot` (veya uygun bir ad)
5. BotFather size bir **API token** verecek (format: `1234567890:ABCdef...`)

### Chat ID Alma
1. Bota `/start` mesajı gönder
2. Tarayıcıda şu URL'yi aç:
   ```
   https://api.telegram.org/bot<TOKEN>/getUpdates
   ```
3. `result[0].message.chat.id` değerini kopyala

### Webhook Ayarlama
Bot'un WordPress'e callback göndermesi için:
```
https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://bitebimuv.org/wp-json/dernek/v1/telegram-webhook
```

### WordPress Ayarları
WP Admin → Ayarlar → AI Senkronizasyon sayfasına eklenecek alanlar:

| Alan | Değer |
|------|-------|
| Telegram Bot Token | BotFather'dan alınan token |
| Telegram Chat ID | getUpdates'ten alınan ID |
| Perplexity API Key | console.perplexity.ai'dan |
| Gemini API Key | aistudio.google.com'dan |
| Claude API Key | console.anthropic.com'dan |

---

## 2. JeSuspended Social Scheduler Entegrasyonu

JeSuspended eklentisi WordPress içinde çalışır ve sosyal medya hesaplarınızı bağlar.

### Hesap Bağlama
1. WP Admin → JeSuspended → Accounts
2. Her platform için hesap ekle:
   - Threads (Meta API)
   - Facebook Page
   - Instagram Business
   - Twitter/X

### Hesap ID'lerini WordPress'e Kaydetme
WP Admin → Ayarlar → AI Senkronizasyon → Sosyal Hesaplar alanına JSON:
```json
{
  "personal": {
    "threads": "account_id_buraya",
    "facebook": "account_id_buraya",
    "instagram": "account_id_buraya"
  },
  "dernek": {
    "threads": "account_id_buraya",
    "facebook": "page_id_buraya"
  },
  "viral": {
    "threads": "account_id_buraya",
    "instagram": "account_id_buraya"
  }
}
```

---

## 3. n8n Workflow Import

### n8n'e Bağlanma
- URL: `https://n8n.bitebimuv.org`

### Environment Variables (n8n Settings → Variables)
| Değişken | Değer |
|----------|-------|
| `APPS_SCRIPT_URL` | `https://script.google.com/macros/s/.../exec` |
| `DERNEK_WEBHOOK_SECRET` | `YOUR_SECRET_TOKEN` |
| `GEMINI_API_KEY` | Gemini API anahtarı |

### Workflow'ları Import Etme
1. n8n → Workflows → Import
2. Sırayla import et:
   - `n8n-workflows/social-media-pipeline.json`
   - `n8n-workflows/telegram-to-publish.json`
3. Her workflow'da credentials ayarla
4. Activate et

### Pipeline Tetikleme (POST isteği)
```bash
curl -X POST https://n8n.bitebimuv.org/webhook/social-pipeline \
  -H "Content-Type: application/json" \
  -d '{
    "topic": "Yapay zeka ve sivil toplum",
    "account_type": "dernek",
    "platforms": ["threads", "facebook"]
  }'
```

---

## 4. WordPress REST API ile Pipeline Çalıştırma

### Doğrudan İçerik Pipeline
```bash
curl -X POST https://bitebimuv.org/wp-json/dernek/v1/pipeline/run \
  -H "X-Dernek-Token: YOUR_SECRET_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "topic": "Gönüllülük trendleri 2025",
    "account_type": "personal",
    "platforms": ["threads", "instagram"]
  }'
```

### Gönderi Listesi
```bash
curl https://bitebimuv.org/wp-json/dernek/v1/social-posts?status=pending_approval \
  -H "X-Dernek-Token: YOUR_SECRET_TOKEN"
```

### Araştırma Drive'a Kaydetme
```bash
curl -X POST https://bitebimuv.org/wp-json/dernek/v1/save-research \
  -H "X-Dernek-Token: YOUR_SECRET_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "topic": "Sivil toplum dijitalleşmesi",
    "content": "Araştırma içeriği buraya...",
    "source": "perplexity"
  }'
```

---

## 5. NotebookLM → Claude Bağlantısı

### Drive Klasörünü NotebookLM'e Ekleme
1. Google Drive → `AI-Projeler` klasörüne git
2. Klasörü paylaş → "Bağlantıya sahip olan herkes okuyabilir"
3. NotebookLM → New Notebook → Add Source → Google Drive
4. `AI-Projeler` klasörünü seç

### Token Tasarrufu Nasıl Çalışır?
- Her AI konuşması otomatik olarak Drive'a `.md` olarak kaydedilir
- Aynı konuyu tekrar araştırmak yerine NotebookLM'e sor
- `getContext` endpoint'i benzer geçmiş araştırmaları otomatik ekler
- Claude prompt'larına bağlam eklenerek tekrarlayan araştırma önlenir

---

## 6. Google Sheets Ayarları

Apps Script'te şunu çalıştır:
1. AI Asistan → Sheets Kurulumunu Çalıştır
2. AI Asistan → Sosyal Medya Sayfalarını Kur (yeni menü öğesi)

Script Properties'e ekle:
- `WEBHOOK_SECRET` = `YOUR_SECRET_TOKEN`
- `MONTHLY_BUDGET_USD` = `20`

---

## 7. 3 Hesap Stratejisi

### Personal (Ömer Faruk Kural)
- Frekans: Haftada 3-4 gönderi
- İçerik: Kişisel düşünceler, dernek deneyimleri, AI araçlarıyla öğrendiklerim
- Platform önceliği: Threads > Instagram

### Dernek
- Frekans: Haftada 2-3 gönderi
- İçerik: Proje duyuruları, sektör haberleri, topluluk başarıları
- Platform önceliği: Facebook > Threads

### Viral
- Frekans: Günlük 1-2 gönderi
- İçerik: Trend konular, viral hook'lar, soru-cevap formatı
- Platform önceliği: Threads > Instagram > Twitter/X

---

## Sorun Giderme

### Telegram onay mesajı gelmiyor
- WP Admin → Ayarlar → AI Senkronizasyon → Token ve Chat ID kontrol et
- Webhook URL'yi yeniden ayarla:
  `https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://bitebimuv.org/wp-json/dernek/v1/telegram-webhook`

### Pipeline çalışmıyor
- `wp-content/debug.log` dosyasını kontrol et
- API anahtarlarının WP Options'da kayıtlı olduğunu doğrula

### n8n webhook tetiklenmiyor
- n8n → Workflow → Execute Once ile test et
- GCP firewall'da 443 portunu kontrol et
