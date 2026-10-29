<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Crawler\HtmlParser;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeoSignalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_and_entity_signals_are_read(): void
    {
        $ld = json_encode(['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'Dentist', 'name' => 'Klinik', 'sameAs' => ['https://instagram.com/k', 'https://facebook.com/k']],
            ['@type' => 'BlogPosting', 'headline' => 'Implant', 'datePublished' => '2024-03-01T10:00:00+03:00', 'author' => ['@type' => 'Person', 'name' => 'Dt. Ayşe']],
        ]]);
        $parsed = HtmlParser::parse('<html><head><script type="application/ld+json">'.$ld.'</script></head><body>'
            .'<h2>İmplant nedir?</h2><h2>Nasıl yapılır</h2><h3>Fiyatlar</h3><p>'.str_repeat('kelime ', 160).'</p><p>kısa</p></body></html>', 'https://example.com/a');

        $this->assertTrue($parsed['is_article']);
        $this->assertSame('2024-03-01', $parsed['published_at']);
        $this->assertNull($parsed['modified_at']);
        $this->assertTrue($parsed['has_author']);
        $this->assertSame(2, $parsed['same_as']);
        $this->assertSame(2, $parsed['question_headings']);
        $this->assertSame(1, $parsed['long_paragraphs']);

        $plain = HtmlParser::parse('<html><head><meta property="og:type" content="article"><meta property="article:modified_time" content="2025-01-02"></head>'
            .'<body class="single single-post"><span class="byline">Yazar</span></body></html>', 'https://example.com/b');
        $this->assertTrue($plain['is_article']);
        $this->assertSame('2025-01-02', $plain['modified_at']);
        $this->assertTrue($plain['has_author']);
        $this->assertFalse(HtmlParser::parse('<html><body><p>x</p></body></html>', 'https://example.com/c')['has_author']);
    }

    public function test_findings(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'gsc_property' => 'sc-domain:example.com']);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'llms_txt' => ['status' => 'ok']]);
        $page = fn (string $path, array $attrs = []) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://example.com'.$path, 'depth' => 1, ...$attrs]);
        $home = $page('/', ['depth' => 0, 'schema_types' => 'Dentist', 'same_as' => 0]);
        $page('/iletisim', ['title' => 'Bize ulaşın']);
        $page('/hakkimizda');
        $old = $page('/blog/eski', ['wp_type' => 'post', 'published_at' => now()->subYears(2), 'has_author' => true]);
        $bare = $page('/blog/yeni', ['is_article' => true, 'long_paragraphs' => 3]);
        DB::table('gsc_page_days')->insert(['project_id' => $project->id, 'day' => now()->subDays(5)->toDateString(), 'page' => $old->url, 'page_hash' => $old->url_hash, 'clicks' => 12, 'impressions' => 100, 'position' => 4]);

        (new Auditor)->run($crawl);

        $rules = fn (CrawlPage $p) => $p->issues()->pluck('detail', 'rule')->all();
        $this->assertArrayHasKey('entity_same_as_missing', $rules($home));
        $this->assertSame('Bulunamayan: gizlilik / KVKK', $rules($home)['trust_pages_missing']);
        $this->assertStringContainsString('12 tıklama', $rules($old)['stale_content']);
        $this->assertArrayNotHasKey('article_author_missing', $rules($old));
        $this->assertArrayHasKey('article_date_missing', $rules($bare));
        $this->assertArrayHasKey('article_author_missing', $rules($bare));
        $this->assertSame('3 paragraf 150 kelimeden uzun', $rules($bare)['long_paragraphs']);
    }
}
