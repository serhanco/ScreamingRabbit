<?php

namespace Tests\Feature;

use App\Crawler\CrawlRunner;
use App\Models\Crawl;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrawlModeTest extends TestCase
{
    use RefreshDatabase;

    private function crawl(array $settings): Crawl
    {
        $sitemap = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/harita/</loc></url></urlset>';
        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            'example.com/sitemap.xml' => Http::response($sitemap, 200, ['Content-Type' => 'application/xml']),
            '*/sitemap_index.xml' => Http::response('', 404),
            '*/wp-sitemap.xml' => Http::response('', 404),
            '*/llms*.txt' => Http::response('', 404),
            '*' => Http::response('<html><head><title>Sayfa</title></head><body><a href="/linkli/">link</a></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $project = Project::factory()->create(['base_url' => 'https://example.com', 'crawl_settings' => ['delay_ms' => 0, 'check_external' => false] + $settings]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);
        while ((new CrawlRunner($crawl->fresh()))->run()) {
        }

        return $crawl->refresh();
    }

    public function test_spider_follows_links(): void
    {
        $this->assertEqualsCanonicalizing(['https://example.com/', 'https://example.com/harita/', 'https://example.com/linkli/'], $this->crawl([])->pages()->pluck('url')->all());
    }

    public function test_sitemap_mode_checks_only_sitemap_urls(): void
    {
        $this->assertEqualsCanonicalizing(['https://example.com/', 'https://example.com/harita/'], $this->crawl(['mode' => 'sitemap'])->pages()->pluck('url')->all());
    }

    public function test_list_mode_checks_only_pasted_urls(): void
    {
        $crawl = $this->crawl(['mode' => 'list', 'list_urls' => "https://example.com/a/\n /b/ \n\nhttps://other.com/c/\nhttps://example.com/a/"]);

        $this->assertEqualsCanonicalizing(['https://example.com/', 'https://example.com/a/', 'https://example.com/b/'], $crawl->pages()->pluck('url')->all());
        $this->assertFalse((bool) $crawl->sitemap_found);
    }
}
