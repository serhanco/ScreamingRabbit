<?php

namespace Tests\Unit;

use App\Support\DataDir;
use PHPUnit\Framework\TestCase;

class DataDirTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/sr-datadir-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/public_html/app', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    public function test_normal_checkout_keeps_project_folder(): void
    {
        $this->assertNull(DataDir::resolve($this->root.'/public_html/app'));
    }

    public function test_deploy_layout_uses_folder_next_to_web_root(): void
    {
        touch($this->root.'/public_html/index.php');

        $dir = DataDir::resolve($this->root.'/public_html/app');

        $this->assertSame($this->root.'/screamingrabbit-data', $dir);
        $this->assertDirectoryExists($dir.'/storage/framework/sessions');
        $this->assertDirectoryExists($dir.'/storage/logs');
    }
}
