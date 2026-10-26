<?php

namespace Tests\Feature;

use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Crawl;
use App\Models\CrawlIssue;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Tasks\TaskBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class TaskListTest extends TestCase
{
    use RefreshDatabase;

    private function crawlWith(Project $project, array $issues): Crawl
    {
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        foreach ($issues as $i => [$rule, $severity, $path]) {
            $page = CrawlPage::firstOrCreate(['crawl_id' => $crawl->id, 'url_hash' => sha1('https://example.com'.$path)], ['url' => 'https://example.com'.$path, 'state' => 'done', 'status_code' => 200]);
            CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $page->id, 'rule' => $rule, 'severity' => $severity]);
        }

        return $crawl;
    }

    public function test_tasks_follow_findings_without_duplicates(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'gsc_property' => 'sc-domain:example.com']);
        DB::table('gsc_page_days')->insert(['project_id' => $project->id, 'day' => now()->subDays(3)->toDateString(), 'page' => 'https://example.com/a', 'page_hash' => sha1('https://example.com/a'), 'clicks' => 40, 'impressions' => 400, 'position' => 3]);

        $this->crawlWith($project, [['title_missing', 'medium', '/a'], ['title_missing', 'medium', '/b'], ['h1_missing', 'medium', '/b'], ['llms_txt_missing', 'info', '/']]);
        TaskBuilder::sync($project);

        $title = Task::where('key', 'rule:title_missing')->first();
        $this->assertSame(2, $title->affected);
        $this->assertSame(40, $title->clicks);
        $this->assertStringContainsString('40 tıklama', $title->why);
        $this->assertGreaterThan(Task::where('key', 'rule:h1_missing')->value('score'), $title->score, 'traffic raises priority');
        $this->assertNull(Task::where('key', 'rule:llms_txt_missing')->first(), 'info findings are not tasks');

        $title->update(['status' => 'done']);
        $this->assertSame(40, $title->fresh()->baseline['clicks']);

        // Next crawl: the title problem is gone, h1 stays, so title is verified and h1 is updated in place.
        $this->crawlWith($project, [['h1_missing', 'medium', '/b']]);
        TaskBuilder::sync($project);
        $this->assertSame('verified', $title->fresh()->status);
        $this->assertSame('open', Task::where('key', 'rule:h1_missing')->value('status'));
        $this->assertSame(2, Task::where('source', 'audit')->count());

        // The problem comes back: the verified task reopens with a note instead of a new row.
        $this->crawlWith($project, [['title_missing', 'medium', '/c']]);
        TaskBuilder::sync($project);
        $this->assertSame('open', $title->fresh()->status);
        $this->assertStringContainsString('yeniden görüldü', $title->fresh()->note);
        $this->assertSame(1, Task::where('key', 'rule:title_missing')->count());
    }

    public function test_task_list_renders(): void
    {
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        $project = Project::factory()->create(['name' => 'Klinik']);
        $this->crawlWith($project, [['http_4xx', 'high', '/gone']]);
        TaskBuilder::sync($project);

        Livewire::test(ListTasks::class)
            ->assertCanSeeTableRecords(Task::all())
            ->assertSee('Klinik')
            ->assertSee('1 sayfayı etkiliyor');
    }
}
