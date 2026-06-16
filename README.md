# Faith Smasher

PHP application dengan struktur MVC modern, siap dikembangkan dan di-deploy ke Hostinger.

## Tech Stack

| Layer | Library |
|---|---|
| Routing | [Slim Framework 4](https://www.slimframework.com/) |
| Templates | [Twig 3](https://twig.symfony.com/) |
| Config | [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) |
| Logging | [Monolog 3](https://github.com/Seldaek/monolog) |
| UI | [Tailwind CSS 4](https://tailwindcss.com/) — tanpa Bootstrap |

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
2. Arahkan **document root** domain ke folder `public/`
3. Atau deploy seluruh repo lalu ubah document root di hPanel

Webhook auto-deploy: push ke branch `master` akan trigger deploy otomatis.

## Environment

Salin `.env.example` ke `.env` dan sesuaikan:

```env
APP_NAME="Faith Smasher"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://domain-kamu.com
```
