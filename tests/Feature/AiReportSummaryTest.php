<?php

namespace Tests\Feature;

use App\Ai\Driver;
use App\Ai\ReportSummary;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\Report;
use App\Models\Setting;
use App\Models\User;
use App\Reports\MonthlyReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiReportSummaryTest extends TestCase
{
    use RefreshDatabase;

    public array $prompts = [];

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();
        $project = Project::factory()->create(['name' => 'Klinik']);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'health_score' => 82, 'finished_at' => now()]);
        CrawlPage::create(['crawl_id' => $crawl->id, 'url' => $project->base_url, 'url_hash' => sha1($project->base_url), 'state' => 'done', 'status_code' => 200]);
        $this->report = MonthlyReports::make($project, $crawl, '2026-10');

        Setting::put('ai_key_anthropic', 'sk-test');
        $test = $this;
        $this->app->instance('ai.driver.anthropic', new class($test) implements Driver
        {
            public function __construct(private AiReportSummaryTest $test) {}

            public function complete(string $model, string $system, string $prompt, int $maxTokens, string $tier, array $images = []): array
            {
                $this->test->prompts[] = compact('model', 'prompt');

                return ['text' => "Siteniz bu ay 82 sağlık puanıyla iyi durumda.\n\nÖnümüzdeki ay kırık bağlantıları düzelteceğiz.", 'input_tokens' => 900, 'output_tokens' => 120, 'model' => $model];
            }
        });
    }

    public function test_the_summary_is_written_from_the_report_data_into_the_draft(): void
    {
        $this->assertNull(ReportSummary::write($this->report));

        $this->assertSame('claude-opus-5-5', $this->prompts[0]['model'], 'the report summary uses the strong model');
        $this->assertStringContainsString('"health_score": 82', $this->prompts[0]['prompt']);
        $report = $this->report->fresh();
        $this->assertStringContainsString('82 sağlık puanıyla', $report->summary);
        $this->assertStringContainsString('Bu dönemin özeti', $report->html);
        $this->assertStringContainsString('kırık bağlantıları', $report->pdf_html);

        $report->approve(User::factory()->create());
        $this->assertSame('Özet yalnızca onay bekleyen raporlara yazılır.', ReportSummary::write($report->fresh()));
        $this->assertFalse(MonthlyReports::setSummary($report->fresh(), 'Başka'), 'approved reports stay as approved');
    }

    public function test_the_reports_page_writes_and_edits_the_summary(): void
    {
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));

        Livewire::test(ListReports::class)
            ->callTableAction('aiSummary', $this->report)
            ->assertNotified('Özet yazıldı');
        $this->assertNotNull($this->report->fresh()->summary);

        Livewire::test(ListReports::class)
            ->callTableAction('editSummary', $this->report, ['summary' => 'Elle yazılmış özet.']);
        $this->assertSame('Elle yazılmış özet.', $this->report->fresh()->summary);
        $this->assertStringContainsString('Elle yazılmış özet.', $this->report->fresh()->html);

        Livewire::test(ListReports::class)
            ->callTableAction('editSummary', $this->report, ['summary' => '']);
        $this->assertStringNotContainsString('Bu dönemin özeti', $this->report->fresh()->html);
    }
}
