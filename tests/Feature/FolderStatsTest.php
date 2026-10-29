<?php

namespace Tests\Feature;

use App\Crawler\FolderStats;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FolderStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_grouped_by_folder(): void
    {
        $this->assertSame('/', FolderStats::folder('https://example.com/'));
        $this->assertSame('/', FolderStats::folder('https://example.com/iletisim'));
        $this->assertSame('/blog/', FolderStats::folder('https://example.com/blog/2024/yazi/'));
        $this->assertSame('/ürün/', FolderStats::folder('https://example.com/%C3%BCr%C3%BCn/a'));

        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = fn (string $path, int $depth, array $attrs = []) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'depth' => $depth, ...$attrs]);
        $page('/', 0, ['link_score' => 100]);
        $page('/blog/a/', 2, ['link_score' => 10]);
        $page('/blog/b/', 5, ['link_score' => 20]);
        $page('/blog/c/', 3, ['status_code' => 404]);

        $rows = collect(FolderStats::for($crawl))->keyBy('folder');
        $this->assertSame(['folder' => '/blog/', 'pages' => 3, 'indexable' => 2, 'errors' => 1, 'avg_depth' => 3.3, 'max_depth' => 5, 'avg_link_score' => 15.0, 'issues' => 0], $rows['/blog/']);
        $this->assertSame(1, $rows['/']['pages']);

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']))
            ->get(CrawlResource::getUrl('view', ['record' => $crawl]))->assertOk()->assertSee('Dizin dağılımı')->assertSee('/blog/');
    }
}
