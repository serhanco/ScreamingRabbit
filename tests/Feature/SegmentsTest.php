<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Crawl;
use App\Models\CrawlIssue;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\User;
use App\Segments\Segments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SegmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_grouped_by_folder_and_depth(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'gsc_property' => 'sc-domain:example.com']);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $make = fn (string $path, int $depth, int $inlinks) => CrawlPage::create([
            'crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'depth' => $depth, 'inlinks' => $inlinks,
        ]);
        $home = $make('/', 0, 0);
        $a = $make('/blog/a', 1, 4);
        $b = $make('/blog/b', 2, 2);
        $make('/urun/x', 3, 1);
        CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $b->id, 'rule' => 'title_missing', 'severity' => 'high']);
        CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $b->id, 'rule' => 'h1_missing', 'severity' => 'medium']);
        CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $a->id, 'rule' => 'x_low', 'severity' => 'low']);
        DB::table('gsc_page_days')->insert(['project_id' => $project->id, 'day' => now()->subDays(2)->toDateString(), 'page' => $a->url, 'page_hash' => $a->url_hash, 'clicks' => 30, 'impressions' => 300, 'position' => 4]);

        $segments = (new Segments($project))->all();
        $blog = $segments['folders']->firstWhere('segment', '/blog/');
        $this->assertSame(2, $blog['pages']);
        $this->assertSame(50, $blog['health'], 'one of two blog pages has no important issue');
        $this->assertSame(1, $blog['high']);
        $this->assertSame(30, $blog['clicks']);
        $this->assertSame(3.0, $blog['avg_inlinks']);
        $this->assertSame(1, $segments['folders']->firstWhere('segment', Segments::ROOT)['pages']);
        $this->assertSame([0, 1, 2, 3], $segments['depths']->pluck('segment')->all());

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']))
            ->get(ProjectResource::getUrl('segments', ['record' => $project]))
            ->assertOk()->assertSee('/blog/')->assertSee('%50');
    }

    public function test_folder_name_from_url(): void
    {
        $this->assertSame('/blog/', Segments::folder('https://x.com/blog/yazi-1'));
        $this->assertSame(Segments::ROOT, Segments::folder('https://x.com/hakkimizda'));
        $this->assertSame(Segments::ROOT, Segments::folder('https://x.com/'));
    }
}
