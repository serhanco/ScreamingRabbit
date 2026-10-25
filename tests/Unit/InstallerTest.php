<?php

namespace Tests\Unit;

use App\Support\Installer;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

class InstallerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sr-installer-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/storage/app', 0777, true);
        file_put_contents($this->dir.'/.env.example', "APP_NAME=ScreamingRabbit\nAPP_KEY=\n# DB_PASSWORD=\nDB_CONNECTION=sqlite\n");
    }

    protected function tearDown(): void
    {
        foreach (['/storage/app/installed', '/storage/app/install.key', '/.env', '/.env.example'] as $file) {
            @unlink($this->dir.$file);
        }
        @rmdir($this->dir.'/storage/app');
        @rmdir($this->dir.'/storage');
        @rmdir($this->dir);
    }

    public function test_env_values_survive_special_characters(): void
    {
        $installer = new Installer($this->dir);
        $password = 'a\'b\\c$d"e #f ${HOME}';

        $env = $installer->buildEnv(['DB_PASSWORD' => $password, 'DB_CONNECTION' => 'mysql', 'APP_URL' => 'https://hekimdijital.com']);
        $parsed = Dotenv::parse($env);

        $this->assertSame($password, $parsed['DB_PASSWORD']);
        $this->assertSame('mysql', $parsed['DB_CONNECTION']);
        $this->assertSame('https://hekimdijital.com', $parsed['APP_URL']);
        $this->assertSame('ScreamingRabbit', $parsed['APP_NAME']);
        $this->assertSame(1, substr_count($env, 'DB_PASSWORD='), 'commented line is replaced, not duplicated');
    }

    public function test_generated_app_key_has_laravel_format(): void
    {
        $key = (new Installer($this->dir))->newAppKey();

        $this->assertStringStartsWith('base64:', $key);
        $this->assertSame(32, strlen(base64_decode(substr($key, 7), true)));
    }

    public function test_install_key_is_required_and_checked(): void
    {
        $installer = new Installer($this->dir);

        $this->assertFalse($installer->verifyKey('anything'), 'no key file means nothing can be installed');

        file_put_contents($installer->keyPath(), "secret-key\n");

        $this->assertTrue($installer->verifyKey('secret-key'));
        $this->assertTrue($installer->verifyKey(' secret-key '));
        $this->assertFalse($installer->verifyKey('wrong'));
        $this->assertFalse($installer->verifyKey(''));
    }

    public function test_marking_installed_locks_the_installer_and_removes_the_key(): void
    {
        $installer = new Installer($this->dir);
        $installer->ensureKey();

        $this->assertFalse($installer->isInstalled());

        $installer->markInstalled();

        $this->assertTrue($installer->isInstalled());
        $this->assertFileDoesNotExist($installer->keyPath());
        $this->assertFalse($installer->verifyKey('k'));
    }

    public function test_ensure_key_creates_a_key_once_and_keeps_it(): void
    {
        $installer = new Installer($this->dir);

        $installer->ensureKey();
        $first = trim((string) file_get_contents($installer->keyPath()));
        $installer->ensureKey();

        $this->assertSame(16, strlen($first));
        $this->assertSame($first, trim((string) file_get_contents($installer->keyPath())));
        $this->assertTrue($installer->verifyKey($first));
    }

    public function test_write_env_creates_the_file(): void
    {
        $installer = new Installer($this->dir);

        $installer->writeEnv($installer->buildEnv(['APP_KEY' => $installer->newAppKey()]));

        $this->assertStringContainsString('APP_KEY=', (string) file_get_contents($this->dir.'/.env'));
    }
}
