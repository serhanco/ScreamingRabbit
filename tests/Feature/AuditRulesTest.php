<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Crawler\HtmlParser;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditRulesTest extends TestCase
{
    use RefreshDatabase;

    private Crawl $crawl;

    protected function setUp(): void
    {
        parent::setUp();

        $project = Project::factory()->create(['platform' => 'wordpress']);
        $this->crawl = Crawl::create(['project_id' => $project->id, 'status' => 'running', 'settings' => []]);
    }

    private function page(string $path, array $attributes = []): CrawlPage
    {
        $url = 'https://example.com'.$path;

        return CrawlPage::create(array_merge([
            'crawl_id' => $this->crawl->id,
            'url' => $url,
            'url_hash' => sha1($url),
            'state' => 'done',
            'status_code' => 200,
            'content_type' => 'text/html',
            'title' => 'A perfectly reasonable page title '.$path,
            'meta_description' => str_repeat('d', 100).$path,
            'h1_count' => 1,
            'h1' => 'Heading '.$path,
            'word_count' => 400,
            'content_hash' => md5($path),
            'canonical' => $url,
            'depth' => 1,
            'inlinks' => 3,
        ], $attributes));
    }

    private function rules(CrawlPage $page): array
    {
        return $page->issues()->pluck('rule')->all();
    }

    public function test_parser_collects_image_mixed_content_and_wordpress_signals(): void
    {
        $html = '<!-- This site is optimized with the Yoast SEO plugin --><html><head><title>t</title></head>'
            .'<body class="archive category"><img src="http://example.com/a.jpg"><img src="b.jpg" alt=""><img src="c.jpg" alt="ok">'
            .'<script src="http://cdn.example.com/x.js"></script><meta http-equiv="refresh" content="0;url=/x"></body></html>';

        $parsed = HtmlParser::parse($html, 'https://example.com/');

        $this->assertSame(1, $parsed['images_missing_alt']);
        $this->assertSame(2, $parsed['mixed_content']);
        $this->assertSame('archive', $parsed['page_type']);
        $this->assertSame('yoast', $parsed['seo_plugin']);
    }

    public function test_new_rules_fire_and_a_clean_site_scores_100(): void
    {
        $home = $this->page('/', ['depth' => 0]);
        $clean = $this->page('/clean');
        $messy = $this->page('/messy', [
            'images_missing_alt' => 2, 'mixed_content' => 1, 'http_internal_links' => 1,
            'meta_refresh' => '0;url=/x', 'inlinks' => 1, 'seo_plugin' => 'yoast',
        ]);
        $attachment = $this->page('/photo', ['page_type' => 'attachment', 'seo_plugin' => 'rankmath']);
        $archive = $this->page('/tag/x', ['page_type' => 'archive', 'word_count' => 50]);
        $broken = $this->page('/gone', ['status_code' => 404, 'title' => null]);
        $pointer = $this->page('/pointer', ['canonical' => 'https://example.com/gone']);
        $slow = $this->page('/slow', ['status_code' => null, 'content_type' => null, 'error' => 'cURL error 28: Operation timed out']);
        $down = $this->page('/down', ['status_code' => null, 'content_type' => null, 'error' => 'Could not resolve host']);

        (new Auditor)->run($this->crawl);

        $this->assertSame([], $this->rules($clean));
        foreach (['images_missing_alt', 'mixed_content', 'https_to_http_link', 'meta_refresh', 'single_inlink'] as $rule) {
            $this->assertContains($rule, $this->rules($messy), $rule);
        }
        $this->assertContains('wp_attachment_page', $this->rules($attachment));
        $this->assertContains('wp_thin_archive', $this->rules($archive));
        $this->assertContains('canonical_target_bad', $this->rules($pointer));
        $this->assertContains('timeout', $this->rules($slow));
        $this->assertContains('fetch_failed', $this->rules($down));
        $this->assertContains('wp_multiple_seo_plugins', $this->rules($home));
        $this->assertContains('http_4xx', $this->rules($broken));

        $score = $this->crawl->fresh()->health_score;
        $this->assertGreaterThan(0, $score);
        $this->assertLessThan(100, $score);
    }

    public function test_site_without_issues_scores_100(): void
    {
        $this->page('/a');
        $this->page('/b');

        (new Auditor)->run($this->crawl);

        $this->assertSame(100, $this->crawl->fresh()->health_score);
    }
}
