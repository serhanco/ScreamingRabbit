<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Audit\Hreflang;
use App\Crawler\HtmlParser;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HreflangTest extends TestCase
{
    use RefreshDatabase;

    public function test_codes_and_tags_are_read(): void
    {
        $this->assertTrue(Hreflang::isValid('tr'));
        $this->assertTrue(Hreflang::isValid('en-GB'));
        $this->assertTrue(Hreflang::isValid('zh-Hant-TW'));
        $this->assertTrue(Hreflang::isValid('x-default'));
        $this->assertFalse(Hreflang::isValid('en-UK'));
        $this->assertFalse(Hreflang::isValid('turkish'));

        $parsed = HtmlParser::parse('<html><head><link rel="canonical" href="/"><link rel="alternate" hreflang="tr" href="/">'
            .'<link rel="alternate" hreflang="en" href="https://example.com/en/"><link rel="alternate" type="application/rss+xml" href="/feed"></head></html>', 'https://example.com/');
        $this->assertSame([['lang' => 'tr', 'url' => 'https://example.com/'], ['lang' => 'en', 'url' => 'https://example.com/en/']], $parsed['hreflang']);
        $this->assertSame('https://example.com/', $parsed['canonical']);
    }

    public function test_language_versions_are_checked_against_each_other(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = fn (string $path, array $attrs = []) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://example.com'.$path, ...$attrs]);
        $tags = fn (array $map) => collect($map)->map(fn ($path, $lang) => ['lang' => $lang, 'url' => 'https://example.com'.$path])->values()->all();

        $tr = $page('/', ['hreflang' => $tags(['tr' => '/', 'en' => '/en/', 'de' => '/de/', 'fr' => '/fr/', 'x-default' => '/'])]);
        $en = $page('/en/', ['hreflang' => $tags(['en-UK' => '/en/', 'tr' => '/'])]);
        $page('/de/', ['hreflang' => $tags(['de' => '/de/', 'en' => '/en/'])]);
        $page('/fr/', ['status_code' => 404, 'canonical' => null]);

        (new Auditor)->run($crawl);

        $rules = fn (CrawlPage $p) => $p->issues()->pluck('detail', 'rule')->all();
        $this->assertSame([
            'hreflang_target_bad' => 'https://example.com/fr/ → HTTP 404',
            'hreflang_missing_return' => 'https://example.com/de/',
        ], array_intersect_key($rules($tr), array_flip(['hreflang_invalid_code', 'hreflang_missing_self', 'hreflang_missing_x_default', 'hreflang_target_bad', 'hreflang_missing_return'])));
        $this->assertSame('en-UK', $rules($en)['hreflang_invalid_code']);
        $this->assertArrayHasKey('hreflang_missing_x_default', $rules($en));
        $this->assertArrayNotHasKey('hreflang_missing_return', $rules($en), 'the Turkish page points back');
    }
}
