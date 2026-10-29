<?php

namespace Tests\Feature;

use App\Crawler\CrawlRunner;
use App\Crawler\CustomExtraction;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomExtractionTest extends TestCase
{
    use RefreshDatabase;

    private const RULES = [
        ['name' => 'GA kodu', 'type' => 'not_contains', 'value' => 'G-ABC123'],
        ['name' => 'Eski telefon', 'type' => 'contains', 'value' => '0212 111 22 33'],
        ['name' => 'Fiyat', 'type' => 'regex', 'value' => 'Fiyat:\s*([\d.]+ TL)'],
        ['name' => 'Yazar', 'type' => 'xpath', 'value' => '//*[@class="author"]'],
        ['name' => '', 'type' => 'contains', 'value' => 'ignored'],
    ];

    public function test_rules_are_applied_to_html(): void
    {
        $html = '<html><head><script>gtag("G-ABC123")</script></head><body><p>Ara: 0212 111 22 33 veya 0212 111 22 33</p><p>Fiyat: 1.500 TL</p><span class="author"> Dt. Ayşe </span></body></html>';

        $this->assertSame(['GA kodu' => false, 'Eski telefon' => 2, 'Fiyat' => '1.500 TL', 'Yazar' => 'Dt. Ayşe'], CustomExtraction::apply(self::RULES, $html));
        $this->assertSame(['GA kodu' => true, 'Eski telefon' => 0, 'Fiyat' => null, 'Yazar' => null], CustomExtraction::apply(self::RULES, '<html><body>boş</body></html>'));
        $this->assertFalse(CustomExtraction::isValidXpath('//*['));
        $this->assertTrue(CustomExtraction::isValidXpath('string(//title)'));
    }

    public function test_results_are_stored_during_the_crawl_and_summarised(): void
    {
        Http::fake([
            'example.com/robots.txt' => Http::response('', 404),
            'example.com/sitemap*' => Http::response('', 404),
            'example.com/wp-sitemap.xml' => Http::response('', 404),
            'example.com/b' => Http::response('<html><body><p>Fiyat: 900 TL</p></body></html>', 200, ['Content-Type' => 'text/html']),
            'example.com/' => Http::response('<html><head><script>G-ABC123</script></head><body><a href="/b">b</a> 0212 111 22 33</body></html>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'crawl_settings' => ['delay_ms' => 0, 'custom' => self::RULES]]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);
        while ((new CrawlRunner($crawl->fresh()))->run()) {
        }

        $b = CrawlPage::where('url', 'https://example.com/b')->firstOrFail();
        $this->assertSame(['GA kodu' => true, 'Eski telefon' => 0, 'Fiyat' => '900 TL', 'Yazar' => null], $b->custom);

        $summary = collect(CustomExtraction::summary($crawl->fresh()))->keyBy('name');
        $this->assertSame(['https://example.com/b'], $summary['GA kodu']['examples']);
        $this->assertSame(1, $summary['Eski telefon']['pages']);
        $this->assertSame(['900 TL'], $summary['Fiyat']['examples']);

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']))
            ->get(CrawlResource::getUrl('view', ['record' => $crawl]))->assertOk()->assertSee('Özel arama sonuçları')->assertSee('900 TL');
    }
}
