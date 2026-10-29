<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Crawler\HtmlParser;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_page_is_found_through_the_schema_graph(): void
    {
        $parsed = HtmlParser::parse('<html><head><script type="application/ld+json">{"@context":"https://schema.org","@graph":['
            .'{"@type":"Article","headline":"Yazı","author":{"@id":"https://example.com/#/person/1"}},'
            .'{"@type":"Person","@id":"https://example.com/#/person/1","name":"Ayşe","url":"https://example.com/author/ayse/"}]}</script></head><body><h1>Yazı</h1></body></html>', 'https://example.com/yazi/');
        $this->assertTrue($parsed['has_author']);
        $this->assertSame('https://example.com/author/ayse/', $parsed['author_url']);
        $this->assertTrue($parsed['person_schema']);

        $parsed = HtmlParser::parse('<html><head><meta property="og:type" content="article"><meta name="author" content="Ayşe"></head><body><p>Metin</p></body></html>', 'https://example.com/yazi/');
        $this->assertTrue($parsed['has_author']);
        $this->assertNull($parsed['author_url']);
        $this->assertFalse($parsed['person_schema']);
    }

    public function test_author_links_and_author_page_schema_are_checked(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = fn (string $path, array $attrs = []) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://example.com'.$path, ...$attrs]);

        $linked = $page('/a/', ['is_article' => true, 'published_at' => '2026-10-01', 'has_author' => true, 'author_url' => 'https://example.com/author/ayse/']);
        $page('/b/', ['is_article' => true, 'published_at' => '2026-10-01', 'has_author' => true, 'author_url' => 'https://example.com/author/ayse/']);
        $unlinked = $page('/c/', ['is_article' => true, 'published_at' => '2026-10-01', 'has_author' => true]);
        $author = $page('/author/ayse/');
        $page('/author/mehmet/', ['person_schema' => true]);

        (new Auditor)->run($crawl);

        $rules = fn (CrawlPage $p) => $p->issues()->pluck('detail', 'rule')->all();
        $this->assertArrayHasKey('author_page_missing', $rules($unlinked));
        $this->assertArrayNotHasKey('author_page_missing', $rules($linked));
        $this->assertSame('2 yazı bu yazar sayfasına bağlı', $rules($author)['author_schema_missing']);
        $this->assertSame(1, $crawl->issues()->where('rule', 'author_schema_missing')->count());
    }
}
