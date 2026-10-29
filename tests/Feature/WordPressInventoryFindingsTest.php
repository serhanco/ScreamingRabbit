<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\WpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordPressInventoryFindingsTest extends TestCase
{
    use RefreshDatabase;

    private function audit(?array $inventory, $syncedAt = null): CrawlPage
    {
        $project = Project::factory()->create(['platform' => 'wordpress']);
        if ($inventory !== null) {
            WpConnection::create(['project_id' => $project->id, 'secret' => 'x', 'connected_at' => now(), 'synced_at' => $syncedAt ?? now(), 'inventory' => $inventory]);
        }
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'detected_platform' => 'wordpress',
            'wp_checks' => ['probes' => ['feed' => ['value' => '6.0'], 'core_latest' => ['value' => '6.9']]]]);
        $home = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/', 'url_hash' => sha1('https://example.com/'), 'state' => 'done',
            'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://example.com/', 'depth' => 0]);

        (new Auditor)->run($crawl);

        return $home;
    }

    public function test_connector_inventory_becomes_findings(): void
    {
        $home = $this->audit([
            'core' => ['version' => '6.8', 'update' => '6.9'],
            'environment' => ['php' => '8.1.30'],
            'settings' => ['blog_public' => false, 'permalink' => '', 'wp_debug' => true, 'wp_debug_display' => true, 'file_edit_disabled' => false, 'admins' => 5],
            'plugins' => [
                ['name' => 'Yoast SEO', 'version' => '25.0', 'active' => true, 'update' => '25.1'],
                ['name' => 'Hello Dolly', 'version' => '1.7', 'active' => false, 'update' => null],
            ],
            'themes' => [['name' => 'Astra', 'version' => '4.0', 'active' => true, 'update' => '4.1']],
        ]);
        $issues = $home->issues()->pluck('detail', 'rule');

        $this->assertSame('Sitede 6.8, güncel 6.9 (eklentiden)', $issues['wp_core_outdated']);
        $this->assertSame('1 eklenti: Yoast SEO 25.0 → 25.1', $issues['wp_plugin_outdated']);
        $this->assertSame('Astra 4.0 → 4.1', $issues['wp_theme_outdated']);
        $this->assertSame('1 pasif eklenti: Hello Dolly', $issues['wp_inactive_plugins']);
        $this->assertSame('WP_DEBUG açık, hatalar ziyaretçilere görünüyor', $issues['wp_debug_enabled']);
        $this->assertSame('5 yönetici', $issues['wp_many_admins']);
        $this->assertSame('PHP 8.1.30', $issues['wp_php_outdated']);
        foreach (['wp_search_engines_discouraged', 'wp_plain_permalinks', 'wp_file_edit_enabled'] as $rule) {
            $this->assertTrue($issues->has($rule), $rule);
        }
        $this->assertSame(1, $home->issues()->where('rule', 'wp_core_outdated')->count(), 'the outside guess is not added on top');
    }

    public function test_without_a_fresh_inventory_the_outside_checks_apply(): void
    {
        $this->assertSame('Sitede 6.0, güncel 6.9', $this->audit(null)->issues()->where('rule', 'wp_core_outdated')->value('detail'));

        $stale = $this->audit(['core' => ['version' => '6.8', 'update' => '6.9'], 'settings' => ['wp_debug' => true]], now()->subDays(8));
        $this->assertSame('Sitede 6.0, güncel 6.9', $stale->issues()->where('rule', 'wp_core_outdated')->value('detail'));
        $this->assertFalse($stale->issues()->where('rule', 'wp_debug_enabled')->exists());
    }
}
