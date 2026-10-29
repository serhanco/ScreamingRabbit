<?php

namespace Tests\Feature;

use App\Exports\CrawlSitemap;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SitemapExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_indexable_pages_with_dates_and_images(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = fn (string $path, array $attrs = []) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'depth' => 1, ...$attrs]);
        $home = $page('/', ['depth' => 0]);
        $page('/blog/a?x=1&y=2', ['modified_at' => '2026-09-01']);
        $page('/etiket', ['meta_robots' => 'noindex, follow']);
        $page('/kopya', ['canonical' => 'https://example.com/']);
        $page('/yok', ['status_code' => 404]);
        $page('/dosya.pdf', ['content_type' => 'application/pdf']);

        foreach (['/logo.png' => 200, '/kirik.png' => 404] as $path => $status) {
            $id = DB::table('crawl_images')->insertGetId(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1($path), 'state' => 'done', 'status_code' => $status]);
            DB::table('crawl_image_refs')->insert(['crawl_id' => $crawl->id, 'image_id' => $id, 'page_id' => $home->id]);
        }

        $out = fopen('php://memory', 'w+');
        $this->assertSame(2, (new CrawlSitemap($crawl))->write($out));
        rewind($out);
        $xml = stream_get_contents($out);

        $this->assertNotFalse(simplexml_load_string($xml), 'valid XML');
        $this->assertStringContainsString('<url><loc>https://example.com/</loc><image:image><image:loc>https://example.com/logo.png</image:loc></image:image></url>', $xml);
        $this->assertStringContainsString('<loc>https://example.com/blog/a?x=1&amp;y=2</loc><lastmod>2026-09-01</lastmod>', $xml);
        $this->assertStringNotContainsString('etiket', $xml);
        $this->assertStringNotContainsString('kirik.png', $xml);
    }
}
