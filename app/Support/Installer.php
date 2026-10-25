<?php

namespace App\Support;

use PDO;

/**
 * Logic behind the one-time browser installer (public/install.php) used on hosting without a terminal.
 * It must work before a .env file, an APP_KEY or a database exist, so it relies on nothing but plain PHP.
 */
class Installer
{
    public function __construct(private string $basePath) {}

    public function isInstalled(): bool
    {
        return is_file($this->basePath.'/storage/app/installed');
    }

    public function keyPath(): string
    {
        return $this->basePath.'/storage/app/install.key';
    }

    /**
     * Creates the install key on first use. It can only be read through the hosting file manager,
     * so whoever completes the installer is someone with access to the hosting account.
     */
    public function ensureKey(): void
    {
        if (! is_file($this->keyPath())) {
            file_put_contents($this->keyPath(), bin2hex(random_bytes(8))."\n", LOCK_EX);
        }
    }

    public function verifyKey(string $given): bool
    {
        $file = $this->keyPath();

        if (! is_file($file) || $given === '') {
            return false;
        }

        return hash_equals(trim((string) file_get_contents($file)), trim($given));
    }

    /**
     * @return array<int, string> Human-readable problems that would stop the installation.
     */
    public function environmentProblems(): array
    {
        $problems = [];

        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            $problems[] = 'PHP 8.3 veya üstü gerekiyor, şu an '.PHP_VERSION.' çalışıyor. hPanel > Advanced > PHP Configuration bölümünden sürümü yükseltin.';
        }

        foreach (['pdo_mysql', 'mbstring', 'openssl', 'curl', 'dom', 'intl'] as $extension) {
            if (! extension_loaded($extension)) {
                $problems[] = "PHP eklentisi eksik: {$extension}";
            }
        }

        foreach (['storage', 'storage/app', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $dir) {
            if (! is_writable($this->basePath.'/'.$dir)) {
                $problems[] = "Klasör yazılabilir değil: {$dir}";
            }
        }

        if (! is_file($this->basePath.'/.env.example')) {
            $problems[] = '.env.example dosyası bulunamadı, yükleme eksik olabilir.';
        }

        return $problems;
    }

    /**
     * @param  array{host: string, port?: string, name: string, user: string, password: string}  $db
     *
     * @throws \PDOException
     */
    public function connect(array $db): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'] ?? '3306', $db['name']);

        return new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    }

    /**
     * True when the database already holds tables that are not ours. A database with our own `migrations`
     * table is fine: it means an earlier install attempt stopped halfway and can simply be continued.
     */
    public function databaseHoldsForeignTables(PDO $pdo): bool
    {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        return $tables !== [] && ! in_array('migrations', $tables, true);
    }

    /**
     * Builds the production .env from .env.example.
     *
     * @param  array<string, string>  $overrides
     */
    public function buildEnv(array $overrides): string
    {
        $env = (string) file_get_contents($this->basePath.'/.env.example');

        foreach ($overrides as $key => $value) {
            $line = $key.'='.$this->quote($value);
            $pattern = '/^#?\s*'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $env)) {
                // Closure, so "$" and "\" inside the value are not read as backreferences.
                $env = preg_replace_callback($pattern, fn () => $line, $env, 1);
            } else {
                $env = rtrim($env)."\n".$line."\n";
            }
        }

        return $env;
    }

    public function writeEnv(string $contents): void
    {
        $path = $this->basePath.'/.env';

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('.env dosyası yazılamadı.');
        }

        @chmod($path, 0640);
    }

    public function newAppKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    public function markInstalled(): void
    {
        file_put_contents($this->basePath.'/storage/app/installed', date('c')."\n");
        @unlink($this->keyPath());
    }

    private function quote(string $value): string
    {
        // Double quotes are the only form dotenv lets us escape in; "$" must be escaped to stop ${VAR} interpolation.
        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
