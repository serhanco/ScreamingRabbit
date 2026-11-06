<?php

namespace Tests\Feature;

use App\Crawler\CrawlRunner;
use App\Exports\SpeedXlsx;
use App\Filament\Pages\SpeedScans;
use App\Filament\Resources\Crawls\Pages\ViewCrawl;
use App\Filament\Resources\Crawls\RelationManagers\SpeedRelationManager;
use App\Models\Crawl;
use App\Models\Project;
use App\Models\Setting;
use App\Models\SpeedResult;
use App\Models\User;
use App\Performance\PageSpeed;
use App\Performance\SpeedScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class SpeedScanTest extends TestCase
{
    use RefreshDatabase;

    private function psi(float $score, ?array $field = null): array
    {
        return [
            'lighthouseResult' => [
                'categories' => ['performance' => ['score' => $score]],
                'audits' => [
                    'first-contentful-paint' => ['numericValue' => 1200.4], 'largest-contentful-paint' => ['numericValue' => 3100.6],
                    'total-blocking-time' => ['numericValue' => 150], 'cumulative-layout-shift' => ['numericValue' => 0.0312],
                    'speed-index' => ['numericValue' => 2800], 'server-response-time' => ['numericValue' => 420],
                ],
            ],
            'loadingExperience' => $field === null ? [] : ['metrics' => [
                'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => $field[0]],
                'INTERACTION_TO_NEXT_PAINT' => ['percentile' => $field[1]],
                'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => $field[2]],
                'FIRST_CONTENTFUL_PAINT_MS' => ['percentile' => 1500],
            ]],
        ];
    }

    /** A small site: the sitemap lists /a/ and /b/; /a/ links to /c/ and back to /b/ (with a tracking fragment). */
    private function fakeSite(array $psi = []): void
    {
        $sitemap = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/a/</loc></url><url><loc>https://example.com/b/</loc></url></urlset>';
        Http::fake([
            PageSpeed::ENDPOINT.'*' => $psi === [] ? Http::response($this->psi(0.8)) : Http::sequence($psi),
            '*/robots.txt' => Http::response('', 404),
            'example.com/urunler-sitemap.xml' => Http::response($sitemap, 200, ['Content-Type' => 'application/xml']),
            '*/sitemap*.xml' => Http::response('', 404),
            '*/wp-sitemap.xml' => Http::response('', 404),
            '*/llms*.txt' => Http::response('', 404),
            'example.com/a/' => Http::response('<html><head><title>A</title></head><body><a href="/c/">c</a><a href="/b/#x">b</a><a href="https://other.com/">dış</a></body></html>', 200, ['Content-Type' => 'text/html']),
            'example.com/logo.png' => Http::response('png', 200, ['Content-Type' => 'image/png']),
            '*' => Http::response('<html><head><title>Sayfa</title></head><body><a href="/a/">a</a></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
    }

    private function runCrawl(Crawl $crawl): Crawl
    {
        while ((new CrawlRunner($crawl->fresh()))->run()) {
        }

        return $crawl->refresh();
    }

    public function test_sitemap_input_discovers_same_site_urls_once_and_measures_both_devices(): void
    {
        $this->fakeSite();
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'crawl_settings' => ['delay_ms' => 0, 'check_external' => false]]);
        $crawl = $this->runCrawl(CrawlRunner::start($project, ['sitemap_url' => 'https://example.com/urunler-sitemap.xml', 'speed_strategies' => ['mobile', 'desktop']], speedScan: true));

        $this->assertSame('completed', $crawl->status);
        $this->assertEqualsCanonicalizing(['https://example.com/', 'https://example.com/a/', 'https://example.com/b/', 'https://example.com/c/'], $crawl->pages()->pluck('url')->all());
        $this->assertSame(8, $crawl->speedResults()->count(), 'four pages, mobile and desktop, no duplicates');
        $this->assertSame(0, SpeedScan::prepare($crawl), 'preparing again adds nothing');

        config(['screamingrabbit.speed_scan.concurrency_without_key' => 3]);
        for ($i = 0; $i < 5; $i++) {
            SpeedScan::round();
        }
        $this->assertSame(8, $crawl->speedResults()->where('state', 'done')->count());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'strategy=desktop') && str_contains($request->url(), urlencode('https://example.com/c/')));

        $row = $crawl->speedResults()->first();
        $this->assertSame([80, 1200, 3101, 150, 0.031, 2800, 420], [$row->score, $row->fcp, $row->lcp, $row->tbt, $row->cls, $row->si, $row->ttfb]);
        $this->assertSame('needs', $row->cwv, 'lab LCP 3.1 s needs improvement');
        $this->assertFalse($row->cwv_from_field);
    }

    public function test_home_page_input_starts_from_that_page(): void
    {
        $this->fakeSite();
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'crawl_settings' => ['delay_ms' => 0, 'check_external' => false, 'mode' => 'sitemap']]);
        $crawl = $this->runCrawl(CrawlRunner::start($project, ['mode' => 'spider', 'start_url' => 'https://example.com/a/'], speedScan: true));

        $this->assertSame('https://example.com/a/', $crawl->pages()->where('depth', 0)->value('url'));
        $this->assertContains('https://example.com/c/', $crawl->pages()->pluck('url')->all());
    }

    public function test_field_data_quota_pause_and_retries(): void
    {
        Http::fake([PageSpeed::ENDPOINT.'*' => Http::sequence()
            ->push($this->psi(0.95, [2100, 180, 5]))
            ->push($this->psi(0.4, [4600, 180, 5]))
            ->push(['error' => ['message' => 'Quota exceeded']], 429)
            ->push(['error' => ['message' => 'Lighthouse returned error: NO_FCP']], 500)
            ->push(['error' => ['message' => '=HYPERLINK("x")']], 500)
            ->push(['error' => ['message' => 'Lighthouse timed out']], 500)]);
        config(['screamingrabbit.speed_scan.concurrency_without_key' => 4]);

        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'speed_scan' => true, 'finished_at' => now()]);
        foreach (['/', '/a', '/b', '/c'] as $path) {
            SpeedResult::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1($path), 'strategy' => 'mobile']);
        }

        $this->assertSame(4, SpeedScan::runDue());
        $rows = $crawl->speedResults()->orderBy('id')->get();
        $this->assertSame(['good', 'poor'], [$rows[0]->cwv, $rows[1]->cwv]);
        $this->assertTrue($rows[0]->cwv_from_field);
        $this->assertSame(0.05, $rows[0]->field_cls);
        $this->assertSame(['pending', 'pending'], [$rows[2]->state, $rows[3]->state], 'quota is retried later; an error gets a second try');
        $this->assertSame(0, $rows[2]->attempts);
        $this->assertTrue(Cache::has(SpeedScan::BACKOFF_KEY));
        $this->assertSame(0, SpeedScan::runDue(), 'paused after a quota answer');

        Cache::forget(SpeedScan::BACKOFF_KEY);
        SpeedScan::round();
        $this->assertSame(['pending', 'failed'], [$rows[2]->fresh()->state, $rows[3]->fresh()->state], 'two errors in a row fail the page');
        $this->assertSame('Lighthouse timed out', $rows[3]->fresh()->error);

        $summary = SpeedScan::summary($crawl);
        $this->assertSame(['done' => 2, 'failed' => 1, 'pending' => 1], ['done' => $summary['done'], 'failed' => $summary['failed'], 'pending' => $summary['pending']]);
        $this->assertSame(68, $summary['strategies']['mobile']['average']);
    }

    public function test_an_api_key_allows_more_measurements_side_by_side(): void
    {
        $this->assertSame(4, SpeedScan::concurrency());
        Setting::put('pagespeed_api_key', 'test-key');
        $this->assertSame(15, SpeedScan::concurrency());
    }

    public function test_pause_stop_and_remeasure(): void
    {
        Http::fake([PageSpeed::ENDPOINT.'*' => Http::response($this->psi(0.9))]);
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'speed_scan' => true, 'finished_at' => now()]);
        foreach (['/', '/a', '/b'] as $path) {
            SpeedResult::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1($path), 'strategy' => 'mobile']);
        }

        Livewire::test(ViewCrawl::class, ['record' => $crawl->id])->callAction('speedPause')->assertNotified('Ölçüm duraklatıldı');
        $this->assertSame(0, SpeedScan::round(), 'a paused scan is skipped');
        $this->assertSame('Duraklatıldı %0', SpeedScans::stateLabel($crawl->fresh()));

        Livewire::test(ViewCrawl::class, ['record' => $crawl->id])->callAction('speedResume');
        config(['screamingrabbit.speed_scan.concurrency_without_key' => 1]);
        $this->assertSame(1, SpeedScan::round());

        Livewire::test(ViewCrawl::class, ['record' => $crawl->id])->callAction('speedStop');
        $this->assertSame(['done', 'cancelled', 'cancelled'], $crawl->speedResults()->orderBy('id')->pluck('state')->all());
        $this->assertSame(0, SpeedScan::summary($crawl)['pending']);

        Livewire::test(ViewCrawl::class, ['record' => $crawl->id])->callAction('speedRetry')->assertNotified('2 sayfa yeniden sıraya alındı');
        $this->assertSame(['done', 'pending', 'pending'], $crawl->speedResults()->orderBy('id')->pluck('state')->all());

        $crawl->speedResults()->update(['state' => 'done']);
        Livewire::test(ViewCrawl::class, ['record' => $crawl->id])->callAction('speedRestart')->assertNotified('3 ölçüm yeniden sıraya alındı');
        $this->assertSame(3, $crawl->speedResults()->where('state', 'pending')->count());

        $one = $crawl->speedResults()->first();
        $one->update(['state' => 'failed', 'attempts' => 2]);
        Livewire::test(SpeedRelationManager::class, ['ownerRecord' => $crawl, 'pageClass' => ViewCrawl::class])->callTableAction('remeasure', $one);
        $this->assertSame(['pending', 0], [$one->fresh()->state, $one->fresh()->attempts]);
    }

    public function test_recrawl_of_a_speed_scan_is_a_speed_scan_again(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'speed_scan' => true, 'finished_at' => now(),
            'settings' => ['mode' => 'spider', 'sitemap_url' => 'https://example.com/s.xml', 'max_urls' => 50, 'speed_strategies' => ['desktop']]]);

        Livewire::test(ViewCrawl::class, ['record' => $crawl->id])->callAction('recrawl');
        $new = Crawl::latest('id')->first();
        $this->assertTrue($new->speed_scan);
        $this->assertSame(['https://example.com/s.xml', 50, ['desktop']], [$new->settings['sitemap_url'], $new->settings['max_urls'], $new->settings['speed_strategies']]);
    }

    public function test_excel_export_has_a_summary_and_a_sheet_per_device(): void
    {
        $project = Project::factory()->create(['name' => 'Klinik Dent']);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'speed_scan' => true, 'finished_at' => now()]);
        SpeedResult::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/', 'url_hash' => sha1('/'), 'page_type' => 'home', 'strategy' => 'mobile', 'state' => 'done', 'score' => 42, 'lcp' => 5200, 'cwv' => 'poor']);
        SpeedResult::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/', 'url_hash' => sha1('/'), 'page_type' => 'home', 'strategy' => 'desktop', 'state' => 'failed', 'error' => '=1+1']);

        $path = (new SpeedXlsx($crawl))->write();
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();
        unlink($path);

        $this->assertSame(['Özet', 'Mobil', 'Masaüstü'], array_keys($sheets));
        $this->assertSame(['https://example.com/', 'Ana sayfa', 42, 'Kötü'], array_slice($sheets['Mobil'][1], 0, 4));
        $this->assertSame(5200, $sheets['Mobil'][1][10]);
        $this->assertSame("'=1+1", $sheets['Masaüstü'][1][17]);
        $this->assertSame('klinik-dent-hiz-raporu-'.$crawl->id.'.xlsx', (new SpeedXlsx($crawl))->filename());
    }

    public function test_page_starts_a_speed_scan_for_the_matching_project(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        $project = Project::factory()->create(['base_url' => 'https://www.klinikdent.com', 'crawl_settings' => ['mode' => 'list']]);

        Livewire::test(SpeedScans::class)
            ->fillForm(['address' => 'https://klinikdent.com/sitemap_index.xml'])
            ->assertSchemaStateSet(['project' => $project->id], 'form')
            ->fillForm(['address' => 'https://klinikdent.com/sitemap_index.xml', 'project' => $project->id, 'strategies' => ['mobile'], 'max_urls' => 300])
            ->call('start')
            ->assertHasNoFormErrors();

        $crawl = Crawl::sole();
        $this->assertTrue($crawl->speed_scan);
        $this->assertSame(['spider', null, 'https://klinikdent.com/sitemap_index.xml', ['mobile'], 300], [$crawl->settings['mode'], $crawl->settings['start_url'], $crawl->settings['sitemap_url'], $crawl->settings['speed_strategies'], $crawl->settings['max_urls']]);
    }

    public function test_page_rejects_an_address_on_another_site(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        $project = Project::factory()->create(['base_url' => 'https://klinikdent.com']);

        Livewire::test(SpeedScans::class)
            ->fillForm(['address' => 'https://baska.com/', 'project' => $project->id, 'strategies' => ['mobile', 'desktop'], 'max_urls' => 100])
            ->call('start')
            ->assertNotified('Adres projenin alan adında değil');
        $this->assertSame(0, Crawl::count());
    }

    public function test_crawl_page_shows_speed_tab_and_summary(): void
    {
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'speed_scan' => true, 'finished_at' => now()]);
        SpeedResult::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/hiz', 'url_hash' => sha1('/hiz'), 'strategy' => 'mobile', 'state' => 'done', 'score' => 91, 'cwv' => 'good', 'cwv_from_field' => true, 'field_lcp' => 1900]);
        SpeedResult::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/bekle', 'url_hash' => sha1('/bekle'), 'strategy' => 'desktop']);

        Livewire::test(ViewCrawl::class, ['record' => $crawl->id])->assertOk()->assertSee('Site geneli hız taraması')->assertSee('Ölçülüyor: 1 / 2');
        Livewire::test(SpeedRelationManager::class, ['ownerRecord' => $crawl, 'pageClass' => ViewCrawl::class])
            ->assertOk()->assertSee('https://example.com/hiz')->assertSee('1,9 sn')->assertSee('İyi');
        $this->get(SpeedScans::getUrl())->assertOk()->assertSee('Hız taraması');

        $plain = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'finished_at' => now()]);
        $this->assertFalse(SpeedRelationManager::canViewForRecord($plain, ViewCrawl::class));
    }
}
