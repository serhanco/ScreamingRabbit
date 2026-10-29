<?php

namespace Tests\Feature;

use App\Crawler\BotAccess;
use App\Crawler\CrawlRunner;
use App\Crawler\HtmlParser;
use App\Crawler\LlmsTxt;
use App\Crawler\RobotsTxt;
use App\Crawler\UrlFilter;
use App\Crawler\UrlNormalizer;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Reports\CrawlReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'elsewhere.com/*' => Http::response('', 200),
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
        $this->assertStringContainsString('Strict-Transport-Security', $byPath('/')->issues()->where('rule', 'security_headers_missing')->value('detail'));
        $this->assertContains('sitemap_missing_indexable', $rules('/b'));
        $this->assertSame(['single_inlink'], $rules('/a'));
        $this->assertSame(1, $byPath('/a')->inlinks);
        $this->assertSame(1, $byPath('/old')->inlinks);
        // Other sites are never crawled, only checked once with HEAD for the broken external link rule.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'elsewhere.com') && $request->method() !== 'HEAD');
        Http::assertSent(fn ($request) => $request->url() === 'https://elsewhere.com/' && $request->method() === 'HEAD');
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

    public function test_include_and_exclude_rules_limit_the_crawl(): void
    {
        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            '*/sitemap*.xml' => Http::response('', 404),
            '*/wp-sitemap.xml' => Http::response('', 404),
            '*' => Http::response($this->page('T', '<a href="/blog/a">a</a><a href="/blog/etiket/x">x</a><a href="/urun/1">1</a>'), 200, ['Content-Type' => 'text/html']),
        ]);

        $project = Project::factory()->create(['base_url' => 'https://example.com', 'crawl_settings' => ['delay_ms' => 0, 'include' => "/blog/\n", 'exclude' => '/etiket/']]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);

        (new CrawlRunner($crawl))->run();

        $this->assertEqualsCanonicalizing(['https://example.com/', 'https://example.com/blog/a'], $crawl->pages()->pluck('url')->all());
    }

    public function test_url_filter_rules(): void
    {
        $filter = new UrlFilter('', "\\?replytocom=\n#comments");

        $this->assertTrue($filter->allows('https://example.com/post'));
        $this->assertFalse($filter->allows('https://example.com/post?replytocom=5'));
        $this->assertNull(UrlFilter::firstInvalid("/blog/\n^https"));
        $this->assertSame('(unclosed', UrlFilter::firstInvalid("/ok/\n(unclosed"));
    }

    public function test_broken_and_large_images_are_reported(): void
    {
        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            '*/sitemap*.xml' => Http::response('', 404),
            '*/wp-sitemap.xml' => Http::response('', 404),
            'example.com/img/ok.jpg' => Http::response('', 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '2048']),
            'example.com/img/big.jpg' => Http::response('', 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) (300 * 1024)]),
            'example.com/img/gone.jpg' => Http::response('', 404),
            'cdn.other.com/*' => Http::response('', 404),
            '*' => Http::response($this->page('T', '<img src="/img/ok.jpg" alt=""><img src="/img/big.jpg" alt=""><img src="/img/gone.jpg" alt=""><img src="https://cdn.other.com/x.jpg" alt="">'), 200, ['Content-Type' => 'text/html']),
        ]);

        $project = Project::factory()->create(['base_url' => 'https://example.com', 'crawl_settings' => ['delay_ms' => 0]]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);

        while ((new CrawlRunner($crawl->fresh()))->run()) {
        }

        $this->assertSame(3, $crawl->images()->count());
        $this->assertSame(1, $crawl->issues()->where('rule', 'image_broken')->count());
        $this->assertSame(1, $crawl->issues()->where('rule', 'image_too_large')->count());
        $this->assertStringContainsString('/img/gone.jpg', $crawl->issues()->where('rule', 'image_broken')->value('detail'));
    }

    public function test_broken_external_links_are_reported(): void
    {
        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            '*/sitemap*.xml' => Http::response('', 404),
            '*/wp-sitemap.xml' => Http::response('', 404),
            'gone.other.com/*' => Http::response('', 404),
            'blocked.other.com/*' => Http::response('', 403),
            'nohead.other.com/*' => fn ($request) => Http::response('', $request->method() === 'HEAD' ? 405 : 200),
            '*' => Http::response($this->page('T', '<a href="https://gone.other.com/x">a</a><a href="https://blocked.other.com/x">b</a><a href="https://nohead.other.com/x">c</a><a href="mailto:a@b.c">m</a>'), 200, ['Content-Type' => 'text/html']),
        ]);

        $project = Project::factory()->create(['base_url' => 'https://example.com', 'crawl_settings' => ['delay_ms' => 0]]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);

        while ((new CrawlRunner($crawl->fresh()))->run()) {
        }

        $this->assertSame(3, DB::table('crawl_external_links')->where('crawl_id', $crawl->id)->count());
        $this->assertSame(['HTTP 404: https://gone.other.com/x'], $crawl->issues()->where('rule', 'external_link_broken')->pluck('detail')->all());
    }

    public function test_bot_access_is_checked_and_reported(): void
    {
        $robots = "User-agent: *\nAllow: /\n\nUser-agent: Bingbot\nDisallow: /\n\nUser-agent: GPTBot\nDisallow: /\n\nUser-agent: PerplexityBot\nDisallow: /\n\nUser-agent: Applebot\nDisallow: /private\n";
        Http::fake(function ($request) use ($robots) {
            $url = $request->url();
            if (str_ends_with($url, '/robots.txt')) {
                return Http::response($robots, 200);
            }
            if (str_contains($url, 'sitemap')) {
                return Http::response('', 404);
            }
            if (str_contains($request->header('User-Agent')[0] ?? '', 'ClaudeBot')) {
                return Http::response('blocked', 403);
            }

            return Http::response($this->page('Home', 'hi'), 200, ['Content-Type' => 'text/html']);
        });

        $project = Project::factory()->create(['base_url' => 'https://example.com', 'crawl_settings' => ['delay_ms' => 0]]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);

        while ((new CrawlRunner($crawl->fresh()))->run()) {
        }
        $crawl->refresh();

        $this->assertFalse($crawl->bot_access['bingbot']['robots_allowed']);
        $this->assertTrue($crawl->bot_access['googlebot']['robots_allowed']);
        // Applebot-Extended has no group of its own, so it falls back to "*" rather than Applebot's rules.
        $this->assertTrue($crawl->bot_access['applebot-extended']['robots_allowed']);
        $this->assertSame('n/a', $crawl->bot_access['google-extended']['fetch']);
        $this->assertSame('refused', $crawl->bot_access['claudebot']['fetch']);
        $this->assertSame([], BotAccess::pending($crawl->bot_access));

        $this->assertStringContainsString('Bingbot', $crawl->issues()->where('rule', 'search_bot_blocked')->value('detail'));
        $this->assertStringContainsString('PerplexityBot', $crawl->issues()->where('rule', 'ai_search_bot_blocked')->value('detail'));
        $this->assertStringContainsString('GPTBot', $crawl->issues()->where('rule', 'ai_training_bot_blocked')->value('detail'));
        $this->assertStringContainsString('ClaudeBot (Anthropic eğitim) (403)', $crawl->issues()->where('rule', 'bot_fetch_refused')->value('detail'));

        $html = (new CrawlReport($crawl))->html();
        $this->assertStringContainsString('AI arama görünürlüğü', $html);
    }

    public function test_structured_data_and_llms_txt_are_checked(): void
    {
        $graph = '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebSite"},{"@type":"Organization","name":"X"}]}</script>';
        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            '*/sitemap*.xml' => Http::response('', 404),
            '*/wp-sitemap.xml' => Http::response('', 404),
            '*/llms.txt' => Http::response("Just some text without a title\n", 200, ['Content-Type' => 'text/plain']),
            'example.com/bad' => Http::response($this->page('Bad', '<script type="application/ld+json">{"@type": "Article",}</script>'), 200, ['Content-Type' => 'text/html']),
            '*' => Http::response($this->page('Home', $graph.'<a href="/bad">bad</a>'), 200, ['Content-Type' => 'text/html']),
        ]);

        $project = Project::factory()->create(['base_url' => 'https://example.com', 'crawl_settings' => ['delay_ms' => 0]]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);

        while ((new CrawlRunner($crawl->fresh()))->run()) {
        }
        $crawl->refresh();

        $this->assertSame('WebSite,Organization', $crawl->pages()->where('depth', 0)->value('schema_types'));
        $this->assertSame(1, $crawl->issues()->where('rule', 'schema_invalid_json')->count());
        $this->assertSame(0, $crawl->issues()->where('rule', 'home_missing_organization_schema')->count());
        $this->assertSame('invalid', $crawl->llms_txt['status']);
        $this->assertFalse($crawl->llms_txt['full']);
        $this->assertSame(1, $crawl->issues()->where('rule', 'llms_txt_invalid')->count());
    }

    public function test_llms_txt_format(): void
    {
        $ok = LlmsTxt::analyze("# Hekim Dijital\n\n> Sağlık iletişimi ajansı.\n\n## Hizmetler\n- [SEO](https://example.com/seo): arama\n");
        $this->assertSame(['status' => 'ok', 'title' => 'Hekim Dijital', 'summary' => true, 'links' => 1], $ok);
        $this->assertSame('missing', LlmsTxt::analyze('<!DOCTYPE html><html></html>')['status']);
        $this->assertSame('missing', LlmsTxt::analyze(null)['status']);
    }

    public function test_wordpress_external_checks(): void
    {
        $home = '<html><head><title>Merhaba dünya! – Site</title><link rel="stylesheet" href="/wp-content/plugins/contact-form-7/style.css?ver=5.1">'
            .'<link rel="stylesheet" href="/wp-content/themes/astra/style.css?ver=4.0"></head><body>Hi</body></html>';
        Http::fake([
            'api.wordpress.org/core/*' => Http::response(['offers' => [['current' => '6.8.3']]]),
            'api.wordpress.org/plugins/*' => Http::response(['version' => '6.0.1']),
            'example.com/robots.txt' => Http::response('', 404),
            'example.com/sitemap.xml' => Http::response('', 404),
            'example.com/sitemap_index.xml' => Http::response('', 200),
            'example.com/wp-sitemap.xml' => Http::response('', 200),
            'example.com/feed/' => Http::response('<generator>https://wordpress.org/?v=6.4.1</generator>', 200),
            'example.com/readme.html' => Http::response('<h1>WordPress</h1>', 200),
            'example.com/license.txt' => Http::response('', 404),
            'example.com/wp-content/debug.log' => Http::response('PHP Notice', 200, ['Content-Type' => 'text/plain', 'Content-Length' => '10']),
            'example.com/wp-content/uploads/' => Http::response('<title>Index of /wp-content/uploads</title>', 200),
            'example.com/wp-content/plugins/' => Http::response('', 200),
            'example.com/xmlrpc.php' => Http::response('XML-RPC server accepts POST requests only.', 405),
            'example.com/wp-json/wp/v2/users' => Http::response([['id' => 1, 'slug' => 'admin']]),
            'example.com/?author=1' => Http::response('', 301, ['Location' => 'https://example.com/author/admin/']),
            'http://example.com/wp-login.php' => Http::response('', 301, ['Location' => 'https://example.com/wp-login.php']),
            'example.com/?s=screamingrabbit' => Http::response('<meta name="robots" content="noindex">', 200),
            'example.com/wp-config*' => Http::response('', 404),
            '*' => Http::response($home, 200, ['Content-Type' => 'text/html']),
        ]);

        $project = Project::factory()->create(['base_url' => 'https://example.com', 'platform' => 'wordpress', 'crawl_settings' => ['delay_ms' => 0]]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);

        while ((new CrawlRunner($crawl->fresh()))->run()) {
        }
        $crawl->refresh();
        $rules = $crawl->issues()->pluck('detail', 'rule');

        $this->assertSame('Sitede 6.4.1, güncel 6.8.3', $rules['wp_core_outdated']);
        $this->assertSame('contact-form-7 5.1 → 6.0.1', $rules['wp_plugin_outdated']);
        $this->assertStringContainsString('astra', $rules['wp_inventory']);
        $this->assertSame('Görünen: admin', $rules['wp_user_enumeration']);
        $this->assertSame('readme.html', $rules['wp_default_files']);
        $this->assertSame('/wp-content/uploads/', $rules['wp_directory_listing']);
        $this->assertTrue($rules->has('wp_xmlrpc_enabled'));
        $this->assertTrue($rules->has('wp_debug_log_public'));
        $this->assertTrue($rules->has('wp_double_sitemap'));
        $this->assertTrue($rules->has('wp_default_content'));
        $this->assertFalse($rules->has('wp_login_http'));
        $this->assertFalse($rules->has('wp_search_indexable'));
        $this->assertFalse($rules->has('wp_config_backup_exposed'));
    }

    public function test_wordpress_checks_are_skipped_for_other_sites(): void
    {
        Http::fake(['*' => Http::response($this->page('T', 'x'), 200, ['Content-Type' => 'text/html'])]);
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'platform' => 'generic', 'crawl_settings' => ['delay_ms' => 0]]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);

        while ((new CrawlRunner($crawl->fresh()))->run()) {
        }

        $this->assertSame(['skipped' => true], $crawl->fresh()->wp_checks);
    }
}
