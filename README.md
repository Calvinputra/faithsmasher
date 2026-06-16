# Faith Smashers

Aplikasi bagan & pencatatan turnamen badminton dengan login/register.

## Tech Stack

| Layer | Library |
|---|---|
| Routing | [Slim Framework 4](https://www.slimframework.com/) |
| Templates | [Twig 3](https://twig.symfony.com/) |
| Database | MySQL (PDO) |
| UI | [Tailwind CSS 4](https://tailwindcss.com/) — palette navy & gold dari logo |

## Brand Colors

| Token | Warna | Penggunaan |
|---|---|---|
| `navy-*` | Biru tua (#102c54) | Teks, header, primary UI |
| `gold-*` | Kuning emas (#f5b800) | CTA, accent, highlight |
| `surface` | Putih | Background card |

## Database Setup

### Local

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS faithsmasher CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root faithsmasher < database/schema.sql
cp .env.example .env
```

### Hostinger (Server)

1. Copy `.env.hostinger.example` ke `.env` di server
2. Pastikan `DB_PASSWORD` sudah benar (sudah terisi di template)
3. **Import schema** — pilih salah satu:
   - **phpMyAdmin:** buka database → tab **Import** → pilih `database/schema.sql` → Go
   - **Terminal hPanel:**
     ```bash
     composer install --no-dev
     php bin/setup-database.php
     ```
4. **Buat superadmin pertama** (wajib, karena register butuh approval):
   ```bash
   php bin/create-admin.php "Calvin" calvin@gmail.com "password-login-anda"
   ```
5. Login di `/login` dengan email & password di atas

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=u753898012_faithsmasher
DB_USER=u753898012_calvin
DB_PASSWORD=          # isi password MySQL dari hPanel
```

> **Penting:** `DB_HOST=localhost` dipakai **di server Hostinger**. Jangan pakai IP (`153.92.15.70`) sebagai `DB_PORT` — port MySQL selalu **3306**. Host remote `srv1980.hstgr.io` hanya untuk koneksi dari luar (perlu aktifkan Remote MySQL + whitelist IP di hPanel).

## Assets

- Logo: `public/assets/images/logo.png`
- CSS source: `resources/css/app.css`
- CSS build: `public/assets/css/app.css`

## Struktur Project

```
faithsmasher/
├── config/              # Konfigurasi aplikasi
├── public/              # Document root (index.php, .htaccess, assets)
│   ├── assets/
│   └── index.php
├── resources/views/     # Template Twig
│   ├── components/
│   ├── layouts/
│   └── pages/
├── routes/              # Definisi route
├── src/
│   └── Controllers/     # Controller layer
└── storage/             # Log & cache template
```

## Instalasi

```bash
composer install
cp .env.example .env
```

## Development

```bash
composer install
npm install
npm run dev        # watch Tailwind CSS
composer start     # PHP server di terminal lain
```

Build CSS untuk production / deploy:

```bash
npm run build
```

Buka [http://localhost:8000](http://localhost:8000)

## Menambah Route Baru

1. Buat controller di `src/Controllers/`
2. Daftarkan route di `routes/web.php`
3. Buat view di `resources/views/pages/`

Contoh:

```php
// routes/web.php
$app->get('/about', [$aboutController, 'index']);
```

## Deploy Hostinger

1. Set **branch** ke `master`
2. Deploy ke root `public_html` (direktori kosong)
3. Setelah deploy, jalankan di **Terminal hPanel**:
   ```bash
   cd ~/domains/skyblue-donkey-768625.hostingersite.com/public_html
   composer install --no-dev --optimize-autoloader
   cp .env.hostinger.example .env
   # edit .env — isi DB_PASSWORD
   php bin/check-server.php
   php bin/setup-database.php
   php bin/create-admin.php "Calvin" calvin@gmail.com "password-login"
   chmod -R 755 storage
   ```
4. Salin `.env.hostinger.example` ke `.env` dan sesuaikan `APP_URL` + `DB_PASSWORD`
5. Pastikan folder `storage/` writable (chmod 755)

### HTTP 500?

| Penyebab | Solusi |
|---|---|
| `vendor/` belum ada | `composer install --no-dev` |
| `.env` belum ada / salah | Copy `.env.hostinger.example`, isi password MySQL |
| Database kosong | Import `database/schema.sql` atau `php bin/setup-database.php` |
| DB_HOST salah | Pakai `localhost` (bukan IP), port `3306` |

Cek otomatis: `php bin/check-server.php`

File `index.php` dan `.htaccess` di root repo sudah disiapkan agar Hostinger
tidak 403 — Apache akan meneruskan request ke folder `public/`.

Alternatif: arahkan **document root** domain ke folder `public/` di hPanel.

## Environment

Salin `.env.example` ke `.env` dan sesuaikan:

```env
APP_NAME="Faith Smasher"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://domain-kamu.com
```
