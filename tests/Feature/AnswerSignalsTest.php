<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Crawler\HtmlParser;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnswerSignalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_answer_signals_are_read_from_the_main_content(): void
    {
        $answer = str_repeat('Diş beyazlatma ortalama bir saat sürer ve etkisi iki yıl kalır. ', 3);
        $html = '<html><body><nav><ul><li><a href="https://facebook.com/x">a</a></li><li>b</li><li>c</li></ul></nav>'
            .'<h2>Diş beyazlatma ne kadar sürer?</h2><p>'.$answer.'</p>'
            .'<h2>Nasıl yapılır</h2><div>Uzun anlatım</div>'
            .'<h2>Fiyatlar</h2><table><tr><td>1</td></tr></table>'
            .'<ol><li>Bir</li><li>İki</li><li>Üç</li></ol>'
            .'<p>Hastaların %80\'i memnun (<a href="https://www.saglik.gov.tr/rapor">Sağlık Bakanlığı</a>, <a href="/iletisim">bize yazın</a>).</p>'
            .'<blockquote>Beyazlatma diş minesine zarar vermez, doğru uygulanırsa güvenlidir.</blockquote><p><q>Harika</q></p>'
            .'<h3>Sıkça sorulan sorular</h3></body></html>';

        $this->assertSame(['questions' => 2, 'answered' => 1, 'lists' => 1, 'tables' => 1, 'faq' => true, 'sources' => 1, 'numbers' => 1, 'quotes' => 1],
            HtmlParser::parse($html, 'https://www.example.com/a/')['aeo']);
    }

    public function test_missing_signals_become_findings(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = fn (string $path, array $attrs) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://example.com'.$path, ...$attrs]);
        $empty = ['questions' => 0, 'answered' => 0, 'lists' => 0, 'tables' => 0, 'faq' => false, 'sources' => 0, 'numbers' => 0];

        $bare = $page('/a/', ['word_count' => 900, 'is_article' => true, 'has_author' => true, 'published_at' => '2026-10-01', 'aeo' => [...$empty, 'questions' => 3]]);
        $good = $page('/b/', ['word_count' => 900, 'is_article' => true, 'has_author' => true, 'published_at' => '2026-10-01', 'aeo' => [...$empty, 'questions' => 3, 'answered' => 1, 'lists' => 2, 'sources' => 1]]);

        (new Auditor)->run($crawl);

        $rules = fn (CrawlPage $p) => $p->issues()->pluck('detail', 'rule')->all();
        $this->assertSame('3 soru başlığı', $rules($bare)['question_answer_missing']);
        $this->assertSame('900 kelime', $rules($bare)['no_lists_tables']);
        $this->assertArrayHasKey('external_sources_missing', $rules($bare));
        $this->assertSame([], array_intersect_key($rules($good), array_flip(['question_answer_missing', 'no_lists_tables', 'external_sources_missing'])));
    }
}
