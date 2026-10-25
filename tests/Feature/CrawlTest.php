<?php

namespace Tests\Feature;

use App\Crawler\CrawlRunner;
use App\Crawler\HtmlParser;
use App\Crawler\RobotsTxt;
use App\Crawler\UrlNormalizer;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrawlTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $title, string $body): string
    {
        return "<html><head><title>{$title}</title></head><body>{$body}</body></html>";
    }

    public function test_urls_are_resolved_and_filtered(): void
    {
        $base = 'https://example.com/blog/post/';

        $this->assertSame('https://example.com/blog/post/next', UrlNormalizer::resolve('next', $base));
        $this->assertSame('https://example.com/about', UrlNormalizer::resolve('/about#team', $base));
        $this->assertSame('https://example.com/blog/x', UrlNormalizer::resolve('../x', $base));
        $this->assertSame('https://other.com/', UrlNormalizer::resolve('//other.com', $base));
        $this->assertNull(UrlNormalizer::resolve('mailto:a@b.co', $base));
        $this->assertNull(UrlNormalizer::resolve('#top', $base));
        $this->assertTrue(UrlNormalizer::isInternal('https://www.example.com/x', 'https://example.com'));
        $this->assertTrue(UrlNormalizer::isAsset('https://example.com/a/file.PDF'));
        $this->assertTrue(UrlNormalizer::isAsset('https://example.com/cdn-cgi/l/email-protection'));
    }

    public function test_robots_longest_rule_wins(): void
    {
        $robots = new RobotsTxt("User-agent: *\nDisallow: /private\nAllow: /private/open\nSitemap: https://example.com/s.xml\n");

        $this->assertFalse($robots->isAllowed('https://example.com/private/x'));
        $this->assertTrue($robots->isAllowed('https://example.com/private/open'));
        $this->assertTrue($robots->isAllowed('https://example.com/blog'));
        $this->assertSame(['https://example.com/s.xml'], $robots->sitemaps);
    }

    public function test_html_parser_extracts_page_data(): void
    {
        $html = '<html><head><title> Hello  World </title><meta name="description" content="Desc"><meta name="robots" content="noindex">'
            .'<link rel="canonical" href="/c"><meta name="generator" content="WordPress 6.5"></head>'
            .'<body><h1>One</h1><h1>Two</h1><p>alpha beta gamma</p><a href="/x" rel="nofollow">x</a><a href="y">y</a><script>var a = "ignored words";</script></body></html>';

        $parsed = HtmlParser::parse($html, 'https://example.com/dir/page');

        $this->assertSame('Hello World', $parsed['title']);
        $this->assertSame('Desc', $parsed['description']);
        $this->assertSame('noindex', $parsed['meta_robots']);
        $this->assertSame('https://example.com/c', $parsed['canonical']);
        $this->assertSame(['One', 'Two'], $parsed['h1']);
        $this->assertTrue($parsed['wordpress_hint']);
        $this->assertSame(7, $parsed['word_count']);
        $this->assertSame('https://example.com/x', $parsed['links'][0]['url']);
        $this->assertTrue($parsed['links'][0]['nofollow']);
        $this->assertSame('https://example.com/dir/y', $parsed['links'][1]['url']);
    }

    public function test_a_crawl_finds_pages_and_reports_issues(): void
    {
        $good = $this->page('A good page title that is long enough', '<h1>Good</h1>'.str_repeat('word ', 250));
        $good = str_replace('<title>', '<meta name="description" content="'.str_repeat('d', 100).'"><link rel="canonical" href="https://example.com/a"><title>', $good);

        Http::fake([
            'example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /private\n"),
            'example.com/sitemap.xml' => Http::response('<urlset><url><loc>https://example.com/</loc></url><url><loc>https://example.com/a</loc></url></urlset>'),
            'example.com/sitemap_index.xml' => Http::response('', 404),
            'example.com/wp-sitemap.xml' => Http::response('', 404),
            'example.com/a' => Http::response($good, 200, ['Content-Type' => 'text/html']),
            'example.com/b' => Http::response($this->page('', '<h1>One</h1><h1>Two</h1>short'), 200, ['Content-Type' => 'text/html']),
            'example.com/missing' => Http::response('nope', 404, ['Content-Type' => 'text/html']),
            'example.com/old' => Http::response('', 301, ['Location' => '/a']),
            'example.com/' => Http::response($this->page('Home', '<a href="/a">a</a><a href="/b">b</a><a href="/missing">m</a><a href="/old">o</a><a href="/private">p</a><a href="https://elsewhere.com/">e</a>'), 200, ['Content-Type' => 'text/html']),
        ]);

        $project = Project::factory()->create(['base_url' => 'https://example.com', 'crawl_settings' => ['delay_ms' => 0, 'concurrency' => 3]]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);

        $this->assertFalse((new CrawlRunner($crawl))->run());

        $crawl->refresh();
        $this->assertSame('completed', $crawl->status);
        $this->assertTrue($crawl->sitemap_found);
        $this->assertSame(6, $crawl->pages()->count());

        $byPath = fn (string $path) => CrawlPage::where('crawl_id', $crawl->id)->where('url', 'https://example.com'.$path)->firstOrFail();
        $rules = fn (string $path) => $byPath($path)->issues()->pluck('rule')->all();

        $this->assertSame(404, $byPath('/missing')->status_code);
        $this->assertContains('http_4xx', $rules('/missing'));
        $this->assertContains('link_to_redirect', $rules('/old'));
        $this->assertContains('blocked_by_robots', $rules('/private'));
        $this->assertContains('title_missing', $rules('/b'));
        $this->assertContains('h1_multiple', $rules('/b'));
        $this->assertContains('low_content', $rules('/b'));
        $this->assertContains('title_too_short', $rules('/'));
        $this->assertContains('sitemap_missing_indexable', $rules('/b'));
        $this->assertSame([], $rules('/a'));
        $this->assertSame(1, $byPath('/a')->inlinks);
        $this->assertSame(1, $byPath('/old')->inlinks);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'elsewhere.com'));
        Http::assertNotSent(fn ($request) => $request->url() === 'https://example.com/private');
    }

    public function test_max_urls_limits_the_crawl(): void
    {
        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            '*/sitemap*.xml' => Http::response('', 404),
            '*/wp-sitemap.xml' => Http::response('', 404),
            '*' => Http::response($this->page('T', '<a href="/1">1</a><a href="/2">2</a><a href="/3">3</a>'), 200, ['Content-Type' => 'text/html']),
        ]);

        $project = Project::factory()->create(['base_url' => 'https://example.com', 'crawl_settings' => ['delay_ms' => 0, 'max_urls' => 2]]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);

        (new CrawlRunner($crawl))->run();

        $this->assertSame(2, $crawl->pages()->count());
    }
}
