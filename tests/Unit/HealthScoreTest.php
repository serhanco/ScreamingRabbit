<?php

namespace Tests\Unit;

use App\Audit\HealthScore;
use App\Models\Crawl;
use App\Models\CrawlIssue;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_rule_costs_30_percent_plus_its_page_share(): void
    {
        $crawl = Crawl::create(['project_id' => Project::factory()->create()->id, 'status' => 'completed', 'settings' => []]);
        $pages = collect(range(1, 4))->map(fn ($i) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => "https://x.test/{$i}", 'url_hash' => sha1((string) $i), 'state' => 'done', 'status_code' => 200]));
        // High on every page: 12 × (0.3 + 0.7) = 12. Medium on one of four pages: 6 × (0.3 + 0.7 × 0.25) = 2.85. Info: 0.
        foreach ($pages as $page) {
            CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $page->id, 'rule' => 'title_missing', 'severity' => 'high']);
        }
        CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $pages[0]->id, 'rule' => 'h1_missing', 'severity' => 'medium']);
        CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $pages[0]->id, 'rule' => 'x_info', 'severity' => 'info']);

        $deductions = HealthScore::deductions($crawl);
        $this->assertSame(['title_missing', 'h1_missing'], $deductions->pluck('rule')->all());
        $this->assertEqualsWithDelta(2.85, $deductions[1]['points'], 0.001);
        $this->assertSame(85, HealthScore::compute($crawl));
    }
}
