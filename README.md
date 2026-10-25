# ScreamingRabbit

Screaming Frog'un yeteneklerini temel alan, GSC verisiyle bağlam kazanan ve bulguları zamanlanmış, önceliklendirilmiş bir iş listesine çeviren butik SEO denetim web uygulaması.

Plan ve kararlar: [docs/](docs/README.md)

## Durum

MVP geliştirme aşamasında. Şu an hazır olanlar: giriş + zorunlu iki adımlı doğrulama (2FA), müşteri ve proje yönetimi, zamanlanmış kuyruk işçisi, CI ve dağıtım betiği. Crawler ve denetim kuralları sırada.

## Teknoloji

PHP 8.3+, Laravel 13, Filament 5 (yönetim paneli), MySQL/MariaDB (yerelde SQLite), cron ile çalışan veritabanı kuyruğu.

## Yerel geliştirme

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan make:filament-user
php artisan serve        # http://localhost:8000/admin
php artisan test
```

Sunucuya kurulum ve güncelleme: [docs/06-kurulum-hostinger.md](docs/06-kurulum-hostinger.md)
