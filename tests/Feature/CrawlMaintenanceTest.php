<?php

namespace Tests\Feature;

use App\Crawler\CrawlMaintenance;
use App\Jobs\CrawlBatch;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function crawl(Project $project, string $status, int $minutesAgo = 0): Crawl
    {
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => $status, 'settings' => []]);
        Crawl::whereKey($crawl->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

        return $crawl;
    }

    public function test_stalled_crawls_are_resumed_and_dead_ones_failed(): void
    {
        Queue::fake();
        $project = Project::factory()->create();
        $fresh = $this->crawl($project, 'running', 2);
        $stalled = $this->crawl($project, 'running', 15);
        $dead = $this->crawl($project, 'pending', 300);

        $this->assertSame(['resumed' => 1, 'failed' => 1], CrawlMaintenance::rescueStalled());

        Queue::assertPushed(CrawlBatch::class, fn ($job) => $job->crawlId === $stalled->id);
        Queue::assertPushed(CrawlBatch::class, 1);
        $this->assertSame('running', $fresh->fresh()->status);
        $this->assertSame('failed', $dead->fresh()->status);
    }

    public function test_old_finished_crawls_are_pruned(): void
    {
        config(['screamingrabbit.crawl_retention' => 2]);
        $project = Project::factory()->create();
        $oldest = $this->crawl($project, 'completed');
        CrawlPage::create(['crawl_id' => $oldest->id, 'url' => 'https://e.com/', 'url_hash' => sha1('a'), 'state' => 'done']);
        $this->crawl($project, 'failed');
        $this->crawl($project, 'completed');
        $running = $this->crawl($project, 'running');

        $this->assertSame(1, CrawlMaintenance::prune());
        $this->assertNull(Crawl::find($oldest->id));
        $this->assertSame(0, CrawlPage::count());
        $this->assertNotNull(Crawl::find($running->id));
        $this->assertSame(3, Crawl::count());
    }
}
