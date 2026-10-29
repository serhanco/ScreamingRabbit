<?php

namespace Tests\Feature;

use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\User;
use App\Performance\PageSpeed;
use App\Performance\PageSpeedTrend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageSpeedTrendTest extends TestCase
{
    use RefreshDatabase;

    public function test_speed_is_followed_across_crawls(): void
    {
        $project = Project::factory()->create();
        $sample = fn (int $score, ?array $field, string $type = 'home') => ['url' => 'https://example.com/'.$type, 'type' => $type, 'state' => 'done', 'score' => $score, 'lab' => [], 'field' => $field];
        $crawl = fn (string $date, array $pagespeed) => Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'finished_at' => $date, 'pagespeed' => $pagespeed]);

        $crawl('2026-09-01 10:00', [$sample(90, ['lcp' => 2000, 'inp' => 150, 'cls' => 0.05]), $sample(80, null, 'post')]);
        Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'finished_at' => '2026-09-15 10:00']);
        $last = $crawl('2026-10-01 10:00', [$sample(60, ['lcp' => 4500, 'inp' => 150, 'cls' => 0.05]), $sample(40, ['lcp' => 5000, 'inp' => 600, 'cls' => 0.3], 'post')]);

        $trend = PageSpeedTrend::upTo($last);
        $this->assertSame(['01.09.2026', '01.10.2026'], array_column($trend, 'date'));
        $this->assertSame([85, 50], array_column($trend, 'average'));
        $this->assertSame([90, 60], array_column($trend, 'home'));
        $this->assertSame([0, 2], array_column($trend, 'poor'));

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']))
            ->get(CrawlResource::getUrl('view', ['record' => $last]))->assertOk()->assertSee('Son taramalarda hız')->assertSee('(-35)')->assertSee('Yazı');
    }

    public function test_samples_carry_their_page_type(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        foreach (['/' => null, '/blog/a' => 'post', '/x' => null] as $path => $type) {
            CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path), 'depth' => $path === '/' ? 0 : 1,
                'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'wp_type' => $type]);
        }

        PageSpeed::prepare($crawl);
        $this->assertSame(['https://example.com/' => 'home', 'https://example.com/blog/a' => 'post', 'https://example.com/x' => null],
            collect($crawl->fresh()->pagespeed)->pluck('type', 'url')->all());
    }
}
