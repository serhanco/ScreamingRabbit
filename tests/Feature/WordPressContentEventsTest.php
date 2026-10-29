<?php

namespace Tests\Feature;

use App\Models\AlertEvent;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\WpConnection;
use App\Models\WpEvent;
use App\WordPress\Connector;
use App\WordPress\ContentChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WordPressContentEventsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::factory()->create(['base_url' => 'https://klinik.com']);
        WpConnection::create(['project_id' => $this->project->id, 'secret' => 'gizli', 'site_url' => 'https://klinik.com/', 'connected_at' => now()]);
    }

    private function send(array $payload, string $secret = 'gizli', ?string $nonce = null)
    {
        $body = json_encode($payload);
        $timestamp = (string) time();
        $nonce ??= str_repeat('a', 20).random_int(1000, 9999);

        return $this->call('POST', '/wp-baglanti/olay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SR_SITE' => 'klinik.com',
            'HTTP_X_SR_TIMESTAMP' => $timestamp,
            'HTTP_X_SR_NONCE' => $nonce,
            'HTTP_X_SR_SIGNATURE' => Connector::sign($secret, 'POST', '/wp-baglanti/olay', $timestamp, $nonce, $body),
        ], $body);
    }

    public function test_signed_events_are_stored_once(): void
    {
        $this->send(['event' => 'published', 'url' => 'https://klinik.com/yeni-yazi/', 'post_type' => 'post'], nonce: 'abcdefghijklmnop0001')->assertStatus(202);
        $this->send(['event' => 'published', 'url' => 'https://klinik.com/yeni-yazi/'], nonce: 'abcdefghijklmnop0001')->assertStatus(401);
        $this->send(['event' => 'published', 'url' => 'https://klinik.com/x/'], 'baska')->assertStatus(401);
        $this->send(['event' => 'published', 'url' => 'https://baska-site.com/x/'])->assertStatus(422);
        $this->send(['event' => 'deleted', 'url' => 'https://klinik.com/x/'])->assertStatus(422);

        $this->assertSame(['https://klinik.com/yeni-yazi/'], WpEvent::pluck('url')->all());
        $this->assertSame('post', WpEvent::first()->post_type);
    }

    public function test_changed_pages_are_checked_and_problems_alerted(): void
    {
        Http::fake([
            'klinik.com/iyi/' => Http::response('<html><head><title>İyi</title><link rel="canonical" href="https://klinik.com/iyi"></head></html>', 200, ['Content-Type' => 'text/html']),
            'klinik.com/gizli/' => Http::response('<html><head><meta name="robots" content="noindex"><link rel="canonical" href="https://klinik.com/baska/"></head></html>', 200, ['Content-Type' => 'text/html']),
            'klinik.com/silindi/' => Http::response('', 404),
            'klinik.com/yeni/' => Http::response('', 200, ['Content-Type' => 'text/html']),
        ]);
        $crawl = Crawl::create(['project_id' => $this->project->id, 'status' => 'completed', 'finished_at' => now(), 'settings' => []]);
        CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://klinik.com/silindi/', 'url_hash' => sha1('https://klinik.com/silindi/'), 'state' => 'done', 'status_code' => 200, 'inlinks' => 4]);
        foreach (['iyi' => 'published', 'gizli' => 'updated', 'silindi' => 'unpublished'] as $path => $event) {
            WpEvent::create(['project_id' => $this->project->id, 'event' => $event, 'url' => "https://klinik.com/{$path}/", 'received_at' => now()->subMinutes(2)]);
        }
        WpEvent::create(['project_id' => $this->project->id, 'event' => 'published', 'url' => 'https://klinik.com/yeni/', 'received_at' => now()]);

        $this->assertSame(3, ContentChecks::runDue(), 'the newest one waits a minute for caches');

        $problems = WpEvent::pluck('problems', 'url');
        $this->assertSame([], $problems['https://klinik.com/iyi/']);
        $this->assertSame(['Sayfa noindex: Google dizine almaz', 'Canonical başka adresi gösteriyor: https://klinik.com/baska/'], $problems['https://klinik.com/gizli/']);
        $this->assertStringContainsString('4 iç link', $problems['https://klinik.com/silindi/'][0]);
        $this->assertNull($problems['https://klinik.com/yeni/']);
        $this->assertSame(2, AlertEvent::where('kind', 'content_problem')->count());
    }
}
