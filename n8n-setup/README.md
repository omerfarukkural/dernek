# n8n Kurulum Rehberi

## Gereksinimler
- GCP VM: n8n-server (34.141.16.229, europe-west3-c)
- GCP Firewall: 443 portu acik olmali
- MySQL DB: n8n_db olusturulmus olmali

## Adimlar

### 1. GCP Firewall (once yapilmali)
```bash
gcloud compute firewall-rules update allow-n8n-ports \
  --allow tcp:80,tcp:443,tcp:5678 \
  --project creator-hub-ai-63948
```

### 2. MySQL DB
cPanel > MySQL Databases > Yeni veritabani: n8n_db
cPanel > MySQL Databases > Yeni kullanici: n8n_user / antakya_1341
n8n_db icin n8n_user'a TUM yetkiler ver

### 3. GCP SSH
```bash
gcloud compute ssh --zone "europe-west3-c" "n8n-server" --project "creator-hub-ai-63948"
```

### 4. Script calistir
```bash
curl -fsSL https://raw.githubusercontent.com/omerfarukkural/dernek/main/n8n-setup/setup.sh | bash
```

## Sonuc
- URL: https://n8n.bitebimuv.org
- Kullanici: admin
- Sifre: antakya_1341
- Webhook Secret: 571632
