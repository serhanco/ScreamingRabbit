<?php

namespace Tests\Feature;

use App\Audit\AiReadiness;
use App\Audit\Auditor;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Reports\CrawlReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_scored_and_summarised_in_the_report(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = fn (string $path, array $attrs) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://example.com'.$path, ...$attrs]);

        $good = $page('/rehber', ['schema_types' => 'Article,FAQPage', 'h2_count' => 4, 'word_count' => 900, 'is_article' => true, 'published_at' => '2026-01-10', 'has_author' => true]);
        $thin = $page('/hizmet', ['h2_count' => 0, 'word_count' => 120, 'long_paragraphs' => 3, 'js_shell' => true]);
        $noindex = $page('/etiket', ['meta_robots' => 'noindex']);

        $this->assertSame(100, AiReadiness::score($good));
        $this->assertArrayNotHasKey('author', AiReadiness::signals($thin), 'date and author only count for articles');
        $this->assertSame(0, AiReadiness::score($thin));

        (new Auditor)->run($crawl);
        $this->assertSame(100, $good->fresh()->ai_score);
        $this->assertSame(0, $thin->fresh()->ai_score);
        $this->assertNull($noindex->fresh()->ai_score);

        $lines = collect((new CrawlReport($crawl))->data()['ai'])->keyBy('label');
        $this->assertSame('50 / 100', $lines['AI hazırlık skoru (sayfa ortalaması)']['value']);
        $this->assertSame('1 / 1', $lines['Yazarı ve tarihi görünen yazılar']['value']);
    }
}
