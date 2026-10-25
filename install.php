<?php

/**
 * One-time browser installer for hosting without a terminal (docs/06).
 * It stays locked until the key stored in storage/app/install.key is entered, and locks itself for good afterwards.
 */

use App\Support\Installer;

error_reporting(E_ALL);
ini_set('display_errors', '0');

$basePath = is_file(__DIR__.'/../vendor/autoload.php') ? realpath(__DIR__.'/..') : realpath(__DIR__.'/app');

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function page(string $title, string $body): never
{
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        .'<title>'.e($title).'</title><style>'
        .'body{font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:2rem auto;padding:0 1rem;color:#1f2937}'
        .'h1{font-size:1.4rem}label{display:block;margin-top:1rem;font-weight:600}'
        .'input{width:100%;padding:.55rem;margin-top:.25rem;border:1px solid #9ca3af;border-radius:6px;font:inherit;box-sizing:border-box}'
        .'button{margin-top:1.5rem;padding:.7rem 1.2rem;border:0;border-radius:6px;background:#ea580c;color:#fff;font:inherit;font-weight:600;cursor:pointer}'
        .'.err{background:#fef2f2;border:1px solid #fca5a5;padding:.75rem 1rem;border-radius:6px;margin-top:1rem}'
        .'.ok{background:#f0fdf4;border:1px solid #86efac;padding:.75rem 1rem;border-radius:6px;margin-top:1rem}'
        .'small{color:#6b7280}code{background:#f3f4f6;padding:.1rem .3rem;border-radius:4px;word-break:break-all}'
        .'</style></head><body>'.$body.'</body></html>';
    exit;
}

if ($basePath === false || ! is_file($basePath.'/vendor/autoload.php')) {
    page('Kurulum', '<h1>Kurulum yapılamıyor</h1><div class="err">Uygulama klasörü (<code>app</code>) bulunamadı. Hostinger\'daki Git ekranından <code>deploy</code> dalının çekildiğinden emin olun.</div>');
}

require $basePath.'/vendor/autoload.php';

$installer = new Installer($basePath, App\Support\DataDir::resolve($basePath));
$installer->ensureKey();

if ($installer->isInstalled()) {
    page('Kurulum tamam', '<h1>Kurulum zaten tamamlanmış</h1><p><a href="/admin">Yönetim paneline git</a></p>');
}

$problems = $installer->environmentProblems();
if ($problems !== []) {
    page('Kurulum', '<h1>Sunucu hazır değil</h1><div class="err"><ul><li>'.implode('</li><li>', array_map('e', $problems)).'</li></ul></div>');
}

$scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$defaults = [
    'key' => '',
    'db_host' => 'localhost',
    'db_name' => '',
    'db_user' => '',
    'app_url' => $scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'localhost'),
    'admin_name' => '',
    'admin_email' => '',
];
$input = $defaults;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($defaults) as $field) {
        $input[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $dbPassword = (string) ($_POST['db_password'] ?? '');
    $adminPassword = (string) ($_POST['admin_password'] ?? '');

    try {
        usleep(random_int(300000, 600000)); // makes guessing the install key slower

        if (! $installer->verifyKey($input['key'])) {
            throw new RuntimeException('Kurulum anahtarı hatalı. Anahtar, screamingrabbit-data/storage/app/install.key dosyasının içindedir.');
        }
        if ($input['db_name'] === '' || $input['db_user'] === '') {
            throw new RuntimeException('Veritabanı adı ve kullanıcı adı gerekli.');
        }
        if (! filter_var($input['app_url'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Site adresi geçerli bir adres olmalı, örneğin https://hekimdijital.com');
        }
        if ($input['admin_name'] === '' || ! filter_var($input['admin_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Yönetici adı ve geçerli bir e-posta gerekli.');
        }
        if (mb_strlen($adminPassword) < 12) {
            throw new RuntimeException('Yönetici şifresi en az 12 karakter olmalı.');
        }

        try {
            $pdo = $installer->connect([
                'host' => $input['db_host'],
                'name' => $input['db_name'],
                'user' => $input['db_user'],
                'password' => $dbPassword,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Veritabanına bağlanılamadı. Veritabanı adı, kullanıcı adı ve şifreyi hPanel\'deki ile karşılaştırın. (Ayrıntı: '.$exception->getMessage().')');
        }

        if ($installer->databaseHoldsForeignTables($pdo)) {
            throw new RuntimeException('Bu veritabanı boş değil. Kurulum için yeni, boş bir veritabanı kullanın.');
        }

        $installer->writeEnv($installer->buildEnv([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => $installer->newAppKey(),
            'APP_URL' => rtrim($input['app_url'], '/'),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $input['db_host'],
            'DB_PORT' => '3306',
            'DB_DATABASE' => $input['db_name'],
            'DB_USERNAME' => $input['db_user'],
            'DB_PASSWORD' => $dbPassword,
        ]));

        $app = require $basePath.'/bootstrap/app.php';
        $app->usePublicPath(__DIR__);
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);

        // A reinstall on an existing database keeps the account but resets its two-factor secret,
        // which can no longer be decrypted with the new application key.
        $admin = App\Models\User::firstOrNew(['email' => $input['admin_email']]);
        $admin->forceFill([
            'name' => $input['admin_name'],
            'password' => $adminPassword,
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ])->save();

        $installer->markInstalled();
        @unlink(__FILE__);

        $cron = 'php '.$basePath.'/artisan schedule:run >> /dev/null 2>&1';
        page('Kurulum tamam', '<h1>Kurulum tamamlandı</h1>'
            .'<div class="ok">Veritabanı hazırlandı ve yönetici hesabı oluşturuldu.</div>'
            .'<h2>Sıradaki adımlar</h2><ol>'
            .'<li><a href="/admin">Yönetim paneline giriş yapın</a>. İlk girişte telefondaki doğrulama uygulamasını kurmanız istenecek.</li>'
            .'<li>hPanel &gt; Advanced &gt; Cron Jobs bölümünde <b>her dakika</b> çalışan bir görev ekleyin. Komut:<br><code>'.e($cron).'</code></li>'
            .'</ol><p><small>Bu kurulum sayfası kendini kilitledi ve silindi.</small></p>');
    } catch (Throwable $exception) {
        $error = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Beklenmeyen bir hata oluştu: '.$exception->getMessage();
    }
}

$field = fn (string $name, string $label, string $type = 'text', string $hint = '') => '<label>'.e($label)
    .'<input name="'.e($name).'" type="'.e($type).'" value="'.($type === 'password' ? '' : e($input[$name] ?? '')).'" required autocomplete="off">'
    .($hint !== '' ? '<small>'.e($hint).'</small>' : '').'</label>';

page('ScreamingRabbit kurulumu', '<h1>ScreamingRabbit kurulumu</h1>'
    .($error !== null ? '<div class="err">'.e($error).'</div>' : '')
    .'<form method="post" autocomplete="off">'
    .$field('key', 'Kurulum anahtarı', 'text', 'Dosya Yöneticisi\'nde screamingrabbit-data/storage/app/install.key dosyasını açın, içindeki metni buraya yapıştırın.')
    .'<h2>Veritabanı</h2>'
    .$field('db_host', 'Sunucu', 'text', 'Hostinger\'da genellikle localhost')
    .$field('db_name', 'Veritabanı adı')
    .$field('db_user', 'Veritabanı kullanıcı adı')
    .$field('db_password', 'Veritabanı şifresi', 'password')
    .'<h2>Site</h2>'
    .$field('app_url', 'Site adresi')
    .'<h2>Yönetici hesabı</h2>'
    .$field('admin_name', 'Adınız')
    .$field('admin_email', 'E-posta')
    .$field('admin_password', 'Şifre', 'password', 'En az 12 karakter.')
    .'<button type="submit">Kurulumu başlat</button></form>');
