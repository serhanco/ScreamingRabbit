<?php

namespace Tests\Feature;

use App\Filament\Resources\Crawls\Pages\ListCrawls;
use App\Filament\Resources\Crawls\Pages\ViewCrawl;
use App\Filament\Resources\Crawls\RelationManagers\IssuesRelationManager;
use App\Filament\Resources\Crawls\RelationManagers\PagesRelationManager;
use App\Filament\Resources\Projects\Actions\StartCrawlAction;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Jobs\CrawlBatch;
use App\Models\Crawl;
use App\Models\CrawlIssue;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\TestCase;

class CrawlPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
    }

    private function crawlWithIssue(): array
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/x', 'url_hash' => sha1('https://example.com/x'), 'state' => 'done', 'status_code' => 404]);
        $issue = CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $page->id, 'rule' => 'http_4xx', 'severity' => 'high', 'detail' => 'HTTP 404']);

        return [$project, $crawl, $page, $issue];
    }

    public function test_crawls_are_listed_and_viewable(): void
    {
        [, $crawl] = $this->crawlWithIssue();

        Livewire::test(ListCrawls::class)->assertCanSeeTableRecords([$crawl]);
        Livewire::test(ViewCrawl::class, ['record' => $crawl->getKey()])->assertSuccessful();
    }

    public function test_issues_and_pages_tabs_show_records(): void
    {
        [, $crawl, $page, $issue] = $this->crawlWithIssue();
        $args = ['ownerRecord' => $crawl, 'pageClass' => ViewCrawl::class];

        Livewire::test(IssuesRelationManager::class, $args)->assertCanSeeTableRecords([$issue]);
        Livewire::test(PagesRelationManager::class, $args)->assertCanSeeTableRecords([$page]);
    }

    public function test_starting_a_crawl_from_a_project_queues_the_first_batch(): void
    {
        Queue::fake();
        $project = Project::factory()->create();

        Livewire::test(ListProjects::class)
            ->callAction(TestAction::make(StartCrawlAction::getDefaultName())->table($project));

        $this->assertDatabaseHas('crawls', ['project_id' => $project->id, 'status' => 'pending']);
        Queue::assertPushed(CrawlBatch::class);
    }

    public function test_pending_migrations_are_applied_once_and_remembered(): void
    {
        $marker = storage_path('app/migrated');
        @unlink($marker);

        $middleware = new \App\Http\Middleware\RunPendingMigrations;
        $method = new \ReflectionMethod($middleware, 'migrateIfNeeded');

        $method->invoke($middleware);

        $this->assertFileExists($marker);
        $first = file_get_contents($marker);
        $method->invoke($middleware);
        $this->assertSame($first, file_get_contents($marker));

        @unlink($marker);
        @unlink(storage_path('app/migrate.lock'));
    }
}
