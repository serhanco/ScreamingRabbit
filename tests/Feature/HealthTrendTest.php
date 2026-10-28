<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\ProjectScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTrendTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_records_the_score_and_it_outlives_the_crawl(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://example.com']);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'running', 'settings' => []]);
        CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/', 'url_hash' => sha1('https://example.com/'), 'state' => 'done', 'status_code' => 404, 'depth' => 0]);

        (new Auditor)->run($crawl);
        (new Auditor)->run($crawl->fresh());

        $score = ProjectScore::sole();
        $this->assertSame($crawl->fresh()->health_score, $score->health_score);
        $this->assertSame(1, $score->pages);
        $this->assertGreaterThan(0, $score->high);

        $crawl->delete();
        $this->assertSame(1, ProjectScore::count(), 'the trend keeps scores of cleaned-up crawls');
    }

    public function test_trend_is_oldest_first_and_shown_on_project_and_report(): void
    {
        $project = Project::factory()->create();
        foreach ([[60, 10], [72, 5], [85, 1]] as $i => [$score, $high]) {
            ProjectScore::create(['project_id' => $project->id, 'scored_at' => now()->subDays(30 - $i * 10), 'health_score' => $score, 'high' => $high]);
        }
        $this->assertSame([60, 72, 85], ProjectScore::trend($project->id)->pluck('score')->all());
        $this->assertSame([60, 72], ProjectScore::trend($project->id, 12, now()->subDays(15))->pluck('score')->all());

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']))
            ->get(ProjectResource::getUrl('edit', ['record' => $project]))
            ->assertOk()->assertSee('Sağlık skoru trendi')->assertSee('+25 puan');

        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'share_token' => 'trend1', 'health_score' => 85, 'finished_at' => now()]);
        $this->get('/rapor/trend1')->assertOk()->assertSee('Sağlık skoru trendi');
        $this->assertStringStartsWith('%PDF', $this->get('/rapor/trend1/pdf')->getContent());
    }
}
