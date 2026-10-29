<?php

namespace Tests\Feature;

use App\Models\Crawl;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Project;
use App\Reports\CrawlReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_shows_search_performance_and_uptime(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'gsc_property' => 'sc-domain:example.com']);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'finished_at' => now()]);
        $day = fn (int $ago, string $path, int $clicks) => ['project_id' => $project->id, 'day' => now()->subDays($ago)->toDateString(), 'page' => 'https://example.com'.$path, 'page_hash' => sha1('https://example.com'.$path), 'clicks' => $clicks, 'impressions' => $clicks * 10, 'position' => 4];
        DB::table('gsc_page_days')->insert([$day(2, '/implant', 60), $day(3, '/', 30), $day(40, '/', 60)]);
        DB::table('gsc_queries')->insert(['project_id' => $project->id, 'page_hash' => sha1('https://example.com/implant'), 'page' => 'https://example.com/implant', 'query' => 'implant fiyatları', 'clicks' => 50, 'impressions' => 400, 'position' => 3]);

        // Every project gets a home-page monitor automatically.
        $monitor = Monitor::query()->where('project_id', $project->id)->firstOrFail();
        DB::table('monitor_daily')->insert(['monitor_id' => $monitor->id, 'day' => now()->subDays(3)->toDateString(), 'checks' => 1000, 'up_checks' => 995, 'avg_ms' => 420]);
        Incident::create(['monitor_id' => $monitor->id, 'started_at' => now()->subDays(3), 'ended_at' => now()->subDays(3)->addMinutes(75), 'cause' => 'timeout']);

        $data = (new CrawlReport($crawl))->data();
        $this->assertSame(90, $data['gsc']['clicks']);
        $this->assertSame(50, $data['gsc']['clicks_change'], '90 against 60 in the 28 days before');
        $this->assertSame('https://example.com/implant', $data['gsc']['pages'][0]->page);
        $this->assertSame(99.5, $data['uptime'][0]['uptime']);
        $this->assertSame(1, $data['uptime'][0]['incidents']);
        $this->assertSame('1 sa 15 dk', $data['uptime'][0]['downtime']);

        $html = (new CrawlReport($crawl))->html();
        $this->assertStringContainsString('Google arama performansı', $html);
        $this->assertStringContainsString('implant fiyatları', $html);
        $this->assertStringContainsString('Erişilebilirlik (son 30 gün)', $html);
        $this->assertNotEmpty((new CrawlReport($crawl))->pdf());
    }

    public function test_sections_are_left_out_without_data(): void
    {
        $crawl = Crawl::create(['project_id' => Project::factory()->create()->id, 'status' => 'completed', 'settings' => []]);
        Monitor::query()->delete();

        $data = (new CrawlReport($crawl))->data();
        $this->assertNull($data['gsc']);
        $this->assertNull($data['uptime']);
        $this->assertStringNotContainsString('Google arama performansı', (new CrawlReport($crawl))->html());
    }
}
