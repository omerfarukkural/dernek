# AI Takip Sistemi — API Dokumantasyonu

## Apps Script Web App

### Base URL

```
https://script.google.com/macros/s/{SCRIPT_ID}/exec
```

### Kimlik Dogrulama

Tum istekler `token` parametresi veya `X-Dernek-Token` header'i gerektirir:

```
?token=your-secret-token
```

veya POST body'sinde:

```json
{ "token": "your-secret-token" }
```

---

## POST /exec — Konusma Logu

### Istek

```
POST https://script.google.com/macros/s/{SCRIPT_ID}/exec
Content-Type: application/json
```

**Govde alanlari:**

| Alan | Tur | Zorunlu | Aciklama |
|------|-----|---------|----------|
| `token` | string | Evet | Webhook secret token |
| `type` | string | Evet | `"conversation"` |
| `device` | string | Hayir | Ornegin: `"chrome-Win32"`, `"iphone"`, `"mac-proxy"` |
| `tool` | string | Hayir | `"claude"`, `"gemini"`, `"perplexity"` |
| `project` | string | Hayir | Proje adi (varsayilan: `"Genel"`) |
| `prompt_summary` | string | Hayir | Kullanici promptunun ozeti (maks 500 karakter) |
| `response_summary` | string | Hayir | AI yanitinin ozeti (maks 1000 karakter) |
| `tokens_used` | number | Hayir | Kullanilan token sayisi |
| `full_content` | string | Hayir | Tam icerik (Drive'a kaydedilir) |

**Ornek curl:**

```bash
curl -X POST "https://script.google.com/macros/s/SCRIPT_ID/exec" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "your-secret-token",
    "type": "conversation",
    "device": "mac-proxy",
    "tool": "claude",
    "project": "Website Yenileme",
    "prompt_summary": "Ana sayfa tasarimini nasil iyilestirebilirim?",
    "response_summary": "Hero section, CTA butonlari ve renk paleti onerileri...",
    "tokens_used": 1250
  }'
```

**Basarili yanit:**

```json
{ "status": "ok", "message": "Logged" }
```

---

## POST /exec — Deploy Logu

### Istek

**Govde alanlari:**

| Alan | Tur | Zorunlu | Aciklama |
|------|-----|---------|----------|
| `token` | string | Evet | Webhook secret token |
| `type` | string | Evet | `"deploy"` |
| `domain` | string | Hayir | Alan adi (ornegin: `"bitebimuv.org"`) |
| `action` | string | Hayir | Islem tipi (ornegin: `"deploy"`, `"rollback"`) |
| `tool` | string | Hayir | Kullanilan arac (`"gcp"`, `"vercel"`, vs.) |
| `status` | string | Hayir | `"success"`, `"failed"`, `"triggered"` |
| `url` | string | Hayir | Deploy edilen URL |
| `notes` | string | Hayir | Ek notlar |

**Ornek curl:**

```bash
curl -X POST "https://script.google.com/macros/s/SCRIPT_ID/exec" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "your-secret-token",
    "type": "deploy",
    "domain": "bitebimuv.org",
    "action": "deploy",
    "tool": "gcp",
    "status": "success",
    "url": "https://bitebimuv.org",
    "notes": "v1.2.3 — Yeni AI sayfasi eklendi"
  }'
```

---

## GET /exec — Proje Listesi

### Istek

```
GET https://script.google.com/macros/s/{SCRIPT_ID}/exec?token=SECRET&action=projects
```

**Yanit:**

```json
["Website Yenileme", "Blog Icerik Uretimi", "API Entegrasyonu"]
```

---

## WordPress REST API

### Base URL

```
https://bitebimuv.org/wp-json/dernek/v1
```

### Kimlik Dogrulama

Header ile:

```
X-Dernek-Token: your-secret-token
```

veya query parametresi ile:

```
?token=your-secret-token
```

---

### POST /wp-json/dernek/v1/log

Konusma logu ekler veya mevcut proje kaydini gunceller.

**Govde alanlari:** `token`, `project`, `tool`, `device`, `tokens_used`, `drive_link`

**Ornek:**

```bash
curl -X POST "https://bitebimuv.org/wp-json/dernek/v1/log" \
  -H "Content-Type: application/json" \
  -H "X-Dernek-Token: your-secret-token" \
  -d '{
    "project": "Website Yenileme",
    "tool": "claude",
    "device": "chrome",
    "tokens_used": 850
  }'
```

**Yanit:**

```json
{
  "success": true,
  "post_id": 42,
  "tokens": 850,
  "url": "https://bitebimuv.org/ai-projeler/website-yenileme/"
}
```

---

### GET /wp-json/dernek/v1/projects

Tum AI projelerini listeler. Kimlik dogrulama gerekmez.

**Opsiyonel parametre:** `?tool=claude`

**Yanit:**

```json
[
  {
    "id": 42,
    "title": "Website Yenileme",
    "tool": "claude",
    "device": "chrome",
    "stage": "Devam Ediyor",
    "tokens": 12500,
    "cost_usd": 0.1875,
    "drive_link": "https://drive.google.com/...",
    "url": "https://bitebimuv.org/ai-projeler/website-yenileme/"
  }
]
```

---

### POST /wp-json/dernek/v1/deploy

Deploy logu ekler.

**Govde alanlari:** `token`, `domain`, `action`, `tool`, `url`, `notes`

---

## Hata Yanitleri

| HTTP Kodu | Anlami |
|-----------|--------|
| `400` | Eksik veya gecersiz veri |
| `401` | Kimlik dogrulama hatasi — token yanlis veya eksik |
| `500` | Sunucu hatasi — Apps Script veya WordPress tarafinda hata |

**Ornek hata yaniti (WordPress):**

```json
{
  "code": "no_data",
  "message": "Veri yok",
  "data": { "status": 400 }
}
```
