<?php

namespace Tests\Feature;

use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Performance\PageSpeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PageSpeedTest extends TestCase
{
    use RefreshDatabase;

    private function psi(float $score, ?array $field): array
    {
        return [
            'lighthouseResult' => [
                'categories' => ['performance' => ['score' => $score]],
                'audits' => ['largest-contentful-paint' => ['numericValue' => 5123.4], 'total-blocking-time' => ['numericValue' => 640], 'cumulative-layout-shift' => ['numericValue' => 0.0312]],
            ],
            'loadingExperience' => $field === null ? [] : ['metrics' => [
                'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => $field[0]],
                'INTERACTION_TO_NEXT_PAINT' => ['percentile' => $field[1]],
                'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => $field[2]],
            ]],
        ];
    }

    public function test_sample_pages_are_measured_and_reported(): void
    {
        Setting::put('pagespeed_api_key', 'test-key');
        Http::fake([PageSpeed::ENDPOINT.'*' => Http::sequence()
            ->push($this->psi(0.38, [4600, 180, 5]))
            ->push($this->psi(0.92, [2100, 250, 2]))
            ->push($this->psi(0.95, null))
            ->push(['error' => ['message' => 'Quota exceeded']], 429)
            ->push($this->psi(0.7, null))]);

        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'finished_at' => now()]);
        $page = fn (string $path, array $attrs = []) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://example.com'.$path, ...$attrs]);
        $page('/', ['depth' => 0, 'link_score' => 100]);
        $page('/blog/a', ['depth' => 2, 'wp_type' => 'post', 'link_score' => 20]);
        $page('/hakkimizda', ['depth' => 1, 'wp_type' => 'page', 'link_score' => 60]);
        $page('/iletisim', ['depth' => 1, 'wp_type' => 'page', 'link_score' => 50]);
        $page('/blog/b', ['depth' => 2, 'wp_type' => 'post', 'link_score' => 10]);
        $page('/blog/c', ['depth' => 2, 'wp_type' => 'post', 'link_score' => 5]);

        PageSpeed::prepare($crawl);
        $this->assertSame(['https://example.com/', 'https://example.com/hakkimizda', 'https://example.com/blog/a', 'https://example.com/iletisim', 'https://example.com/blog/b'],
            array_column($crawl->fresh()->pagespeed, 'url'), 'home, the strongest page of each type, then by Link Score');

        for ($i = 0; $i < 6; $i++) {
            PageSpeed::runDue();
        }
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'key=test-key') && str_contains($request->url(), 'strategy=mobile'));
        Http::assertSentCount(5);

        $results = $crawl->fresh()->pagespeed;
        $this->assertSame(38, $results[0]['score']);
        $this->assertSame(['lcp' => 5123, 'tbt' => 640, 'cls' => 0.031], $results[0]['lab']);
        $this->assertSame(['lcp' => 4600, 'inp' => 180, 'cls' => 0.05], $results[0]['field']);
        $this->assertNull($results[2]['field']);
        $this->assertSame('failed', $results[3]['state']);
        $this->assertStringContainsString('API anahtarı', $results[3]['error']);

        $issue = fn (string $rule) => $crawl->issues()->where('rule', $rule)->first();
        $this->assertSame('LCP 4,6 sn (gerçek kullanıcılar, mobil)', $issue('cwv_poor')->detail);
        $this->assertSame('INP 250 ms (gerçek kullanıcılar, mobil)', $issue('cwv_needs_improvement')->detail);
        $this->assertStringStartsWith('Mobil puan 38/100 · LCP 5,1 sn', $issue('pagespeed_low_score')->detail);
        $this->assertSame(1, $crawl->issues()->where('rule', 'pagespeed_low_score')->count());

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']))
            ->get(CrawlResource::getUrl('view', ['record' => $crawl]))->assertOk()->assertSee('Sayfa hızı (mobil)')->assertSee('Yeterli ziyaretçi verisi yok');
    }
}
