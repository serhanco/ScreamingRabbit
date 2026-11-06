<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\RelationManagers\CrawlsRelationManager;
use App\Models\Crawl;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectCrawlHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        $this->project = Project::factory()->create(['name' => 'Klinik Dent', 'base_url' => 'https://klinikdent.com']);
    }

    private function crawl(array $attributes = []): Crawl
    {
        return Crawl::create($attributes + ['project_id' => $this->project->id, 'status' => 'completed', 'settings' => ['mode' => 'spider'],
            'health_score' => 81, 'started_at' => now()->subHour(), 'finished_at' => now()]);
    }

    private function table()
    {
        return Livewire::test(CrawlsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class]);
    }

    public function test_the_project_page_lists_normal_and_speed_crawls(): void
    {
        $normal = $this->crawl();
        $speed = $this->crawl(['speed_scan' => true, 'health_score' => null]);
        foreach ([90, 70] as $i => $score) {
            DB::table('speed_results')->insert(['crawl_id' => $speed->id, 'url' => "https://klinikdent.com/{$i}", 'url_hash' => sha1((string) $i),
                'strategy' => 'mobile', 'state' => 'done', 'score' => $score]);
        }
        DB::table('speed_results')->insert(['crawl_id' => $speed->id, 'url' => 'https://klinikdent.com/x', 'url_hash' => sha1('x'), 'strategy' => 'mobile', 'state' => 'pending']);
        $other = Crawl::create(['project_id' => Project::factory()->create()->id, 'status' => 'completed']);

        $this->table()
            ->assertCanSeeTableRecords([$normal, $speed])
            ->assertCanNotSeeTableRecords([$other])
            ->assertSee('SEO taraması')
            ->assertSee('Hız taraması')
            ->assertSee('Ort. 80')
            ->assertSee('2 / 3 ölçüm');

        $this->get(ProjectResource::getUrl('edit', ['record' => $this->project]))->assertOk()->assertSee('Tarama geçmişi');
    }

    public function test_past_crawls_can_be_deleted_but_running_ones_only_stopped(): void
    {
        $done = $this->crawl();
        $running = $this->crawl(['status' => 'running', 'finished_at' => null]);

        $this->table()
            ->assertTableActionHidden('delete', $running)
            ->assertTableActionVisible('cancel', $running)
            ->callTableAction('delete', $done);

        $this->assertDatabaseMissing('crawls', ['id' => $done->id]);
        $this->assertDatabaseHas('crawls', ['id' => $running->id]);
    }
}
