#!/bin/bash
# n8n Kurulum Scripti - bitebimuv.org
# GCP VM: n8n-server (34.141.16.229, europe-west3-c)
# Kullanim: bash setup.sh
set -e

echo "=== n8n Kurulum Scripti ==="
echo "Host: n8n.bitebimuv.org"
echo ""

# Docker kur
if ! command -v docker &> /dev/null; then
    echo "[1/5] Docker kuruluyor..."
    curl -fsSL https://get.docker.com | sh
    sudo usermod -aG docker bitebim2
    newgrp docker
fi

echo "[2/5] n8n dizini hazirlaniyor..."
mkdir -p /home/bitebim2/n8n1/data
cd /home/bitebim2/n8n1

echo "[3/5] docker-compose.yml olusturuluyor..."
cat > /home/bitebim2/n8n1/docker-compose.yml << 'EOF'
version: '3.8'
services:
  n8n:
    image: docker.n8n.io/n8nio/n8n:latest
    container_name: n8n
    restart: always
    ports:
      - "5678:5678"
    environment:
      - N8N_HOST=n8n.bitebimuv.org
      - N8N_PORT=5678
      - N8N_PROTOCOL=https
      - WEBHOOK_URL=https://n8n.bitebimuv.org/
      - N8N_WEBHOOK_SECRET=571632
      - GENERIC_TIMEZONE=Europe/Istanbul
      - N8N_BASIC_AUTH_ACTIVE=true
      - N8N_BASIC_AUTH_USER=admin
      - N8N_BASIC_AUTH_PASSWORD=antakya_1341
      - N8N_ENCRYPTION_KEY=571632_bitebimuv_n8n_enc
      - DB_TYPE=mysqldb
      - DB_MYSQLDB_HOST=srvc03.trwww.com
      - DB_MYSQLDB_PORT=3306
      - DB_MYSQLDB_DATABASE=n8n_db
      - DB_MYSQLDB_USER=n8n_user
      - DB_MYSQLDB_PASSWORD=antakya_1341
    volumes:
      - /home/bitebim2/n8n1/data:/home/node/.n8n
EOF

echo "[4/5] Nginx + SSL kuruluyor..."
sudo apt-get update -qq
sudo apt-get install -y nginx certbot python3-certbot-nginx

sudo tee /etc/nginx/sites-available/n8n > /dev/null << 'NGINX'
server {
    listen 80;
    server_name n8n.bitebimuv.org;
    location / {
        proxy_pass http://localhost:5678;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_buffering off;
        proxy_read_timeout 300;
    }
}
NGINX

sudo ln -sf /etc/nginx/sites-available/n8n /etc/nginx/sites-enabled/n8n
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

echo "[5/5] n8n baslatiliyor..."
cd /home/bitebim2/n8n1
docker compose up -d
sleep 10
docker compose ps

echo ""
echo "SSL sertifikasi aliniyor..."
sudo certbot --nginx -d n8n.bitebimuv.org \
    --non-interactive --agree-tos \
    -m admin@bitebimuv.org

echo ""
echo "=== KURULUM TAMAMLANDI ==="
echo "URL  : https://n8n.bitebimuv.org"
echo "User : admin"
echo "Pass : antakya_1341"
echo ""
echo "Log: docker compose -f /home/bitebim2/n8n1/docker-compose.yml logs -f"
