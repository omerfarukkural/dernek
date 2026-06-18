# Apps Script Web App — API Dokümantasyonu

Base URL: `https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec`

Tüm isteklerde `token` alanı zorunludur. Bu değer Script Properties'deki `WEBHOOK_SECRET` ile eşleşmelidir.

---

## POST /exec — Konuşma Kaydı

AI aracıyla yapılan bir konuşmayı Google Sheets'e kaydeder ve Drive'a dosya oluşturur.

**Request Headers:**
```
Content-Type: application/json
```

**Request Body:**
```json
{
  "token": "your-secret-token",
  "type": "conversation",
  "device": "chrome-extension",
  "tool": "claude",
  "project": "Website Yenileme",
  "prompt_summary": "Kullanıcının gönderdiği mesajın özeti (max 500 karakter)",
  "response_summary": "AI yanıtının özeti (max 1000 karakter)",
  "tokens_used": 1250,
  "full_content": "İsteğe bağlı tam içerik",
  "url": "https://claude.ai/chat/abc123"
}
```

**Alan Açıklamaları:**

| Alan | Tip | Zorunlu | Açıklama |
|------|-----|---------|----------|
| token | string | Evet | Kimlik doğrulama tokeni |
| type | string | Evet | `"conversation"` olmalı |
| device | string | Hayır | Kayıt cihazı (örn. `chrome-extension`, `mac-proxy`, `ios-shortcut`) |
| tool | string | Hayır | AI aracı adı (`claude`, `gemini`, `perplexity`) |
| project | string | Hayır | Proje adı, yoksa `"Genel"` kullanılır |
| prompt_summary | string | Hayır | Prompt özeti |
| response_summary | string | Hayır | Yanıt özeti |
| tokens_used | number | Hayır | Kullanılan token sayısı |
| full_content | string | Hayır | Tam konuşma içeriği (Drive dosyasına yazılır) |
| url | string | Hayır | Konuşmanın URL'si |

**Başarılı Yanıt (200):**
```json
{
  "success": true
}
```

**Hata Yanıtı:**
```json
{
  "error": "Unauthorized"
}
```

---

## POST /exec — Deploy Kaydı

Bir hosting veya deploy işlemini Sheets'e kaydeder.

**Request Body:**
```json
{
  "token": "your-secret-token",
  "type": "deploy",
  "domain": "bitebimuv.org",
  "action": "GitHub Push Deploy",
  "tool": "github",
  "status": "Triggered",
  "url": "https://github.com/kullanici/repo",
  "notes": "main branch'e push — otomatik deploy tetiklendi"
}
```

**Alan Açıklamaları:**

| Alan | Tip | Zorunlu | Açıklama |
|------|-----|---------|----------|
| token | string | Evet | Kimlik doğrulama tokeni |
| type | string | Evet | `"deploy"` olmalı |
| domain | string | Hayır | Etkilenen alan adı |
| action | string | Hayır | Yapılan işlem açıklaması |
| tool | string | Hayır | Kullanılan araç (örn. `github`, `vercel`, `gcp`) |
| status | string | Hayır | İşlem durumu (örn. `Triggered`, `Success`, `Failed`) |
| url | string | Hayır | İlgili URL |
| notes | string | Hayır | Ek notlar |

**Başarılı Yanıt (200):**
```json
{
  "success": true
}
```

---

## GET /exec?action=projects — Proje Listesi

Mevcut proje adlarının listesini döner. Chrome Extension popup'ında proje seçimi için kullanılır.

**Request:**
```
GET https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec?token=your-secret-token&action=projects
```

**Query Parametreleri:**

| Parametre | Zorunlu | Açıklama |
|-----------|---------|----------|
| token | Evet | Kimlik doğrulama tokeni |
| action | Evet | `"projects"` olmalı |

**Başarılı Yanıt (200):**
```json
[
  "Website Yenileme",
  "Blog Yazıları",
  "E-ticaret Modülü",
  "Genel"
]
```

**Hata Yanıtı:**
```json
{
  "error": "Unauthorized"
}
```

---

## Notlar

- Tüm POST istekleri `Content-Type: application/json` header'ı ile gönderilmelidir.
- Token doğrulama başarısız olursa HTTP 200 ile `{"error": "Unauthorized"}` döner (Apps Script kısıtlaması).
- Rate limit: Apps Script'in günlük kota limitleri geçerlıdır (6 dakika/gün execution süresi, ücretsiz hesapta).
- Yeni bir deployment yapıldığında URL değişir; tüm istemcilerde URL'yi güncellemeyi unutmayın.
