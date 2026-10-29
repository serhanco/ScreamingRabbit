<?php

namespace Tests\Feature;

use App\Crawler\CrawlRunner;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RobotsSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_typed_robots_txt_replaces_the_live_one_for_the_crawl(): void
    {
        Http::fake([
            'example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /a/\n"),
            'example.com/' => Http::response('<html><body><a href="/a/">a</a><a href="/etiket/x/">x</a></body></html>', 200, ['Content-Type' => 'text/html']),
            'example.com/a/' => Http::response('<html><body>a</body></html>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'crawl_settings' => ['delay_ms' => 0, 'robots_override' => "User-agent: *\nDisallow: /etiket/\n"]]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);
        while ((new CrawlRunner($crawl->fresh()))->run()) {
        }

        $state = CrawlPage::where('crawl_id', $crawl->id)->pluck('state', 'url');
        $this->assertSame('done', $state['https://example.com/a/'], 'the live rule no longer applies');
        $this->assertSame('blocked', $state['https://example.com/etiket/x/']);
        $this->assertStringContainsString('Disallow: /a/', $crawl->fresh()->robots_txt, 'the live file is still recorded');

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']))
            ->get(CrawlResource::getUrl('view', ['record' => $crawl]))->assertOk()->assertSee('deneme robots.txt ile yapıldı');
    }
}
