<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrphanPagesTest extends TestCase
{
    use RefreshDatabase;

    private function crawl(int $maxUrls): array
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => ['max_urls' => $maxUrls], 'sitemap_found' => true]);
        $page = fn (string $path, array $attrs) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://example.com'.$path, 'in_sitemap' => true, ...$attrs]);

        $home = $page('/', ['depth' => 0, 'inlinks' => 0]);
        $blog = $page('/blog/', ['depth' => 1, 'inlinks' => 1]);
        $orphan = $page('/eski-kampanya/', ['depth' => 1, 'inlinks' => 0]);
        $missing = $page('/silinen/', ['depth' => 1, 'inlinks' => 2, 'status_code' => 404, 'canonical' => null]);
        foreach ([$home, $blog] as $from) {
            DB::table('crawl_links')->insert(['crawl_id' => $crawl->id, 'from_page_id' => $from->id, 'to_hash' => $missing->url_hash]);
        }

        (new Auditor)->run($crawl);

        return [$home, $orphan, $missing];
    }

    public function test_sitemap_pages_without_inlinks_are_orphans(): void
    {
        [$home, $orphan, $missing] = $this->crawl(100);

        $this->assertTrue($orphan->issues()->where('rule', 'orphan_page')->exists());
        $this->assertFalse($home->issues()->where('rule', 'orphan_page')->exists());
        $this->assertSame('HTTP 404, 2 iç linkten erişiliyor (örn. /, /blog/)', $missing->issues()->where('rule', 'http_4xx')->value('detail'));
    }

    public function test_orphans_are_not_judged_when_the_url_limit_stopped_the_crawl(): void
    {
        [, $orphan] = $this->crawl(4);

        $this->assertFalse($orphan->issues()->where('rule', 'orphan_page')->exists());
    }
}
