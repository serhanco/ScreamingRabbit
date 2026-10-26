<?php

namespace Tests\Feature;

use App\Crawler\CrawlScheduler;
use App\Jobs\CrawlBatch;
use App\Models\Crawl;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_projects_start_overnight_only_once(): void
    {
        Queue::fake();
        $daily = Project::factory()->create(['crawl_schedule' => 'daily']);
        $weekly = Project::factory()->create(['crawl_schedule' => 'weekly']);
        $manual = Project::factory()->create(['crawl_schedule' => null]);
        $recent = Project::factory()->create(['crawl_schedule' => 'weekly']);
        foreach ([[$daily, 2], [$weekly, 8], [$recent, 3]] as [$project, $age]) {
            $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
            $crawl->forceFill(['created_at' => now()->subDays($age)])->save();
        }

        // Midday: nothing starts.
        $this->travelTo(now()->setTime(13, 0));
        $this->assertSame(0, CrawlScheduler::runDue());

        // 02:00: the daily and the week-old weekly project start; manual and recently crawled ones do not.
        $this->travelTo(now()->setTime(2, 0));
        $this->assertSame(2, CrawlScheduler::runDue());
        $this->assertSame(1, $daily->crawls()->where('status', 'pending')->count());
        $this->assertSame(0, $manual->crawls()->count());
        $this->assertSame(1, $recent->crawls()->count());
        Queue::assertPushed(CrawlBatch::class, 2);

        // A crawl is now pending, so the next tick starts nothing.
        $this->assertSame(0, CrawlScheduler::runDue());
    }
}
