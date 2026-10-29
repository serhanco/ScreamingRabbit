<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Crawler\HtmlParser;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_and_prev_links_are_read(): void
    {
        $parsed = HtmlParser::parse('<html><head><link rel="prev" href="/blog/"><link rel="next" href="/blog/page/3/"></head>'
            .'<body><a href="/blog/">1</a></body></html>', 'https://example.com/blog/page/2/');

        $this->assertSame(['next' => 'https://example.com/blog/page/3/', 'prev' => 'https://example.com/blog/'], $parsed['pagination']);
    }

    public function test_series_pages_are_checked(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = fn (string $path, array $attrs = []) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://example.com'.$path, ...$attrs]);

        $first = $page('/blog/', ['pagination' => ['next' => 'https://example.com/blog/page/2/', 'next_linked' => true]]);
        $second = $page('/blog/page/2/', ['canonical' => 'https://example.com/blog/', 'pagination' => [
            'prev' => 'https://example.com/blog/', 'prev_linked' => true, 'next' => 'https://example.com/blog/page/3/', 'next_linked' => false,
        ]]);
        $page('/blog/page/3/', ['status_code' => 404, 'canonical' => null]);
        $other = $page('/a/', ['canonical' => 'https://example.com/b/']);

        (new Auditor)->run($crawl);

        $rules = fn (CrawlPage $p) => $p->issues()->pluck('detail', 'rule')->all();
        $this->assertSame('https://example.com/blog/', $rules($second)['pagination_canonical']);
        $this->assertArrayNotHasKey('canonical_other', $rules($second));
        $this->assertSame('sonraki: https://example.com/blog/page/3/ → HTTP 404', $rules($second)['pagination_target_bad']);
        $this->assertSame('sonraki: https://example.com/blog/page/3/', $rules($second)['pagination_not_linked']);
        $this->assertArrayNotHasKey('pagination_not_linked', $rules($first));
        $this->assertArrayNotHasKey('pagination_target_bad', $rules($first));
        $this->assertArrayHasKey('canonical_other', $rules($other));
        $this->assertArrayNotHasKey('pagination_canonical', $rules($other));
    }
}
