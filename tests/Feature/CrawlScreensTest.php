<?php

namespace Tests\Feature;

use App\Filament\Resources\Crawls\Pages\ListCrawls;
use App\Filament\Resources\Crawls\Pages\ViewCrawl;
use App\Jobs\CrawlBatch;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CrawlScreensTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        $this->project = Project::factory()->create(['name' => 'Klinik Dent', 'base_url' => 'https://klinikdent.com']);
    }

    private function crawl(string $status, int $pages = 3): Crawl
    {
        $crawl = Crawl::create(['project_id' => $this->project->id, 'status' => $status, 'settings' => $this->project->effectiveCrawlSettings(),
            'health_score' => $status === 'completed' ? 78 : null, 'started_at' => now()->subMinutes(14), 'finished_at' => $status === 'running' ? null : now()]);
        for ($i = 0; $i < $pages; $i++) {
            $url = "https://klinikdent.com/s{$i}";
            $page = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => $url, 'url_hash' => sha1($url), 'state' => 'done', 'status_code' => $i === 0 ? 404 : 200]);
            DB::table('crawl_issues')->insert(['crawl_id' => $crawl->id, 'page_id' => $page->id, 'rule' => 'title_missing', 'severity' => 'high']);
        }

        return $crawl;
    }

    public function test_the_crawl_page_shows_the_overview(): void
    {
        $crawl = $this->crawl('completed');

        Livewire::test(ViewCrawl::class, ['record' => $crawl->id])
            ->assertSee('Klinik Dent')
            ->assertSee('klinikdent.com')
            ->assertSee('/ 100 sağlık')
            ->assertSee('Bulunamadı (4xx)')
            ->assertSee('3 bulgu');

        $running = $this->crawl('running');
        Livewire::test(ViewCrawl::class, ['record' => $running->id])
            ->assertSee('ilerleme')
            ->assertActionVisible('cancel')
            ->assertActionHidden('delete');
    }

    public function test_a_running_crawl_is_stopped_and_a_finished_one_deleted_from_the_list(): void
    {
        $running = $this->crawl('running');
        $done = $this->crawl('completed');

        Livewire::test(ListCrawls::class)
            ->assertTableActionVisible('cancel', $running)
            ->assertTableActionHidden('delete', $running)
            ->assertTableActionHidden('cancel', $done)
            ->callTableAction('cancel', $running)
            ->callTableAction('delete', $done);

        $this->assertSame('cancelled', $running->fresh()->status);
        $this->assertNull($done->fresh());
        $this->assertSame(0, DB::table('crawl_pages')->where('crawl_id', $done->id)->count());
        $this->assertSame(0, DB::table('crawl_issues')->where('crawl_id', $done->id)->count());
    }

    public function test_bulk_delete_skips_running_crawls(): void
    {
        $running = $this->crawl('running');
        $old = $this->crawl('completed');
        $failed = $this->crawl('failed');

        Livewire::test(ListCrawls::class)
            ->callTableBulkAction('delete', [$running, $old, $failed])
            ->assertNotified('2 tarama silindi');

        $this->assertSame([$running->id], Crawl::pluck('id')->all());
    }

    public function test_deleting_from_the_crawl_page_returns_to_the_list(): void
    {
        $crawl = $this->crawl('completed');

        Livewire::test(ViewCrawl::class, ['record' => $crawl->id])
            ->callAction('delete')
            ->assertRedirect(route('filament.admin.resources.crawls.index'));
        $this->assertNull($crawl->fresh());
    }

    public function test_a_new_crawl_is_started_from_the_list(): void
    {
        Queue::fake();

        Livewire::test(ListCrawls::class)
            ->callAction('start', ['project_id' => $this->project->id])
            ->assertNotified('Tarama başlatıldı');

        $crawl = Crawl::sole();
        $this->assertSame('pending', $crawl->status);
        Queue::assertPushed(CrawlBatch::class);

        Livewire::test(ListCrawls::class)
            ->callAction('start', ['project_id' => $this->project->id])
            ->assertNotified('Bu proje için bir tarama zaten sürüyor')
            ->assertRedirect(route('filament.admin.resources.crawls.view', $crawl));
        $this->assertSame(1, Crawl::count());
    }
}
