-- n8n MySQL Veritabani Kurulumu
-- cPanel MySQL Databases bolumunden veya bu SQL ile olusturun
-- srvc03.trwww.com:3306

CREATE DATABASE IF NOT EXISTS n8n_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'n8n_user'@'%'
  IDENTIFIED BY 'antakya_1341';

GRANT ALL PRIVILEGES ON n8n_db.* TO 'n8n_user'@'%';

FLUSH PRIVILEGES;

SELECT 'n8n_db olusturuldu.' AS durum;
