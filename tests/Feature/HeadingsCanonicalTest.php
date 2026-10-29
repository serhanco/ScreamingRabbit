<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Crawler\CrawlRunner;
use App\Crawler\HtmlParser;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeadingsCanonicalTest extends TestCase
{
    use RefreshDatabase;

    private function parse(string $html): array
    {
        return HtmlParser::parse("<html>{$html}</html>", 'https://example.com/a');
    }

    public function test_heading_outline(): void
    {
        $this->assertNull($this->parse('<body><h1>A</h1><h2>B</h2><h3>C</h3><h2>D</h2><h3>E</h3></body>')['heading_skip']);
        $this->assertSame('H2 → H4', $this->parse('<body><h1>A</h1><h2>B</h2><h4>C</h4></body>')['heading_skip']);
        $this->assertSame('başta → H3', $this->parse('<body><h3>Menü</h3><h1>A</h1></body>')['heading_skip']);
        $this->assertNull($this->parse('<body><h2>Logo</h2><h1>A</h1><h2>B</h2></body>')['heading_skip'], 'a leading H2 is common in themes');
        $this->assertSame(2, $this->parse('<body><h1>A</h1><h2>B</h2><h2>C</h2></body>')['h2_count']);
    }

    public function test_canonical_sources(): void
    {
        $this->assertTrue($this->parse('<head><link rel="canonical" href="/a"><link rel="canonical" href="/b"></head>')['canonical_conflict']);
        $this->assertFalse($this->parse('<head><link rel="canonical" href="/a"><link rel="canonical" href="https://example.com/a"></head>')['canonical_conflict']);
        $this->assertSame('https://example.com/a', CrawlRunner::headerCanonical('<https://example.com/a>; rel="canonical"', 'https://example.com/x'));
        $this->assertSame('https://example.com/b', CrawlRunner::headerCanonical('<https://cdn.example.com/s.css>; rel=preload, </b>; rel=canonical', 'https://example.com/x'));
        $this->assertNull(CrawlRunner::headerCanonical('<https://example.com/wp-json/>; rel="https://api.w.org/"', 'https://example.com/x'));
    }

    public function test_findings(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = fn (string $path, array $attrs) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'word_count' => 500, 'h2_count' => 3, ...$attrs]);
        $a = $page('/a', ['canonical' => 'https://example.com/b', 'canonical_conflict' => true]);
        $page('/b', ['canonical' => 'https://example.com/c']);
        $c = $page('/c', ['canonical' => 'https://example.com/c', 'h2_count' => 0, 'heading_skip' => 'H2 → H4']);

        (new Auditor)->run($crawl);

        $rules = fn (CrawlPage $p) => $p->issues()->pluck('detail', 'rule')->all();
        $this->assertSame('https://example.com/b → https://example.com/c', $rules($a)['canonical_chain']);
        $this->assertSame('H2 → H4', $rules($c)['headings_non_sequential']);
        $this->assertArrayHasKey('canonical_conflict', $rules($a));
        $this->assertArrayHasKey('h2_missing', $rules($c));
        $this->assertArrayNotHasKey('h2_missing', $rules($a));
    }
}
