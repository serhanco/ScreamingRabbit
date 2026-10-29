<?php

namespace Tests\Feature;

use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\AlertEvent;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Reports\MonthlyReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    private function crawled(string $name, int $daysAgo): Project
    {
        $project = Project::factory()->create(['name' => $name]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'health_score' => 82, 'finished_at' => now()->subDays($daysAgo)]);
        CrawlPage::create(['crawl_id' => $crawl->id, 'url' => $project->base_url, 'url_hash' => sha1($project->base_url), 'state' => 'done', 'status_code' => 200]);

        return $project;
    }

    public function test_drafts_are_made_once_at_the_start_of_the_month_and_published_after_approval(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 2)->setTime(5, 0));
        $active = $this->crawled('Klinik', 3);
        $stale = $this->crawled('Eski', 90);

        $this->assertSame(1, MonthlyReports::generateDue());
        $this->assertSame(0, MonthlyReports::generateDue(), 'one report per project and month');
        $report = Report::first();
        $this->assertSame([$active->id, '2026-10', 'draft'], [$report->project_id, $report->period, $report->status]);
        $this->assertSame(0, $stale->reports()->count(), 'no recent crawl, no report');
        $this->assertSame(1, AlertEvent::where('kind', 'report_ready')->count());

        // Drafts are not public.
        $this->assertNull($report->url());

        $report->approve(User::factory()->create());
        $report->refresh();
        $this->get($report->url())->assertOk()->assertSee('Klinik')->assertSee(route('monthly-report.pdf', $report->token), false);
        $this->get(route('monthly-report.pdf', $report->token))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // The archive lists approved reports, and keeps working after the crawl itself is pruned.
        Crawl::query()->delete();
        $this->get(route('report.archive', $active->fresh()->archive_token))->assertOk()->assertSee('Ekim 2026')->assertSee($report->url(), false);
        $this->get($report->url())->assertOk();

        // Mid-month nothing new is made.
        $this->travelTo(now()->setDate(2026, 11, 20));
        $this->assertSame(0, MonthlyReports::generateDue());
    }

    public function test_reports_page_lists_and_approves(): void
    {
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        $project = $this->crawled('Klinik', 1);

        Livewire::test(ListReports::class)
            ->callAction('make', ['project_id' => $project->id])
            ->assertHasNoActionErrors();
        $report = Report::firstOrFail();
        $this->assertSame(now()->format('Y-m'), $report->period);

        Livewire::test(ListReports::class)
            ->assertCanSeeTableRecords([$report])
            ->callTableAction('approve', $report);
        $this->assertSame('approved', $report->fresh()->status);

        $this->get(route('filament.admin.reports.preview', $report))->assertOk()->assertSee('Klinik');
    }
}
