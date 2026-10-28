<?php

namespace Tests\Feature;

use App\Crawler\CrawlAlerts;
use App\Crawler\CrawlMaintenance;
use App\Filament\Resources\AlertEvents\AlertEventResource;
use App\Google\GscDrop;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\Crawl;
use App\Models\CrawlIssue;
use App\Models\CrawlPage;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Monitoring\Alerts;
use App\Monitoring\MonitorRunner;
use App\Monitoring\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Monitored sites are down, the canary sites (our own connection) are fine.
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true]), '*.test*' => Http::response('', 503), '*' => Http::response('', 204)]);
        Setting::put('telegram_bot_token', '123:abc');
    }

    /** @return array<int, string> texts sent to Telegram */
    private function telegrams(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn ($request) => str_contains($request->url(), 'api.telegram.org'))
            ->map(fn ($request) => $request['chat_id'].': '.strtok($request['text'], "\n"))->values()->all();
    }

    public function test_every_alert_is_kept_and_routed_by_severity(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '42']);
        $project = Project::factory()->create(['name' => 'Klinik']);

        Alerts::send('ssl', 'SSL bitiyor: Klinik', 'x', $project->id);

        $event = AlertEvent::sole();
        $this->assertSame('high', $event->severity);
        $this->assertSame(['email' => null, 'telegram' => true], AlertDelivery::sole()->channels, 'high goes to Telegram only');
        $this->assertSame(['42: SSL bitiyor: Klinik'], $this->telegrams());

        // Same alert again within 6 hours: kept in the list, not sent again.
        Alerts::send('ssl', 'SSL bitiyor: Klinik', 'x', $project->id);
        $this->assertSame(['sent', 'suppressed'], AlertDelivery::orderBy('id')->pluck('status')->all());
        $this->assertCount(1, $this->telegrams());

        $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->actingAs($user)->get(AlertEventResource::getUrl('index'))->assertOk()->assertSee('SSL bitiyor: Klinik');
    }

    public function test_high_alerts_fall_back_to_email_without_telegram(): void
    {
        $user = User::factory()->create(['alert_email' => 'a@example.com', 'telegram_chat_id' => null]);
        Setting::put('smtp_username', 'bildirim@example.com');
        Mail::fake();

        Alerts::send('slow', 'Yavaş: Klinik', 'x', Project::factory()->create()->id);

        $this->assertSame([], $this->telegrams());
        $this->assertArrayHasKey('email', AlertDelivery::sole()->channels);
        $this->assertNotNull(AlertDelivery::sole()->channels['email']);
    }

    public function test_quiet_hours_hold_non_critical_alerts_until_they_end(): void
    {
        // 23:00-08:00 Istanbul; 22:00 UTC is 01:00 in Istanbul.
        $this->travelTo(now()->setTimezone('UTC')->setTime(22, 0));
        $user = User::factory()->create(['telegram_chat_id' => '42', 'quiet_from' => 23, 'quiet_to' => 8]);
        $project = Project::factory()->create(['name' => 'Klinik']);

        Alerts::send('gsc_drop', 'GSC düştü: Klinik', 'x', $project->id);
        Alerts::send('down', 'Erişilemiyor: Klinik', 'x', $project->id);
        $this->assertSame(['42: Erişilemiyor: Klinik'], $this->telegrams(), 'critical goes out even in quiet hours');
        $this->assertSame(0, Notifier::releaseHeld());

        $this->travelTo(now()->setTime(5, 30)->addDay()); // 08:30 Istanbul
        $this->assertSame(1, Notifier::releaseHeld());
        $this->assertSame('42: Sessiz saatlerde gelen 1 uyarı', $this->telegrams()[1]);
        $this->assertSame(0, AlertDelivery::where('status', 'held')->count());
    }

    public function test_three_sites_down_together_arrive_as_one_message(): void
    {
        User::factory()->create(['telegram_chat_id' => '42']);
        foreach (['A', 'B', 'C'] as $name) {
            Project::factory()->create(['name' => $name, 'base_url' => 'http://'.strtolower($name).'.test']);
        }
        $this->assertSame(3, Monitor::count());

        MonitorRunner::run(0);

        $this->assertSame(3, AlertEvent::where('kind', 'down')->count());
        $this->assertSame(['42: 🔴 Toplu kesinti: 3 site erişilemiyor'], $this->telegrams());
    }

    public function test_crawl_alerts_new_high_issues_and_failures(): void
    {
        User::factory()->create(['telegram_chat_id' => '42']);
        $project = Project::factory()->create(['name' => 'Klinik']);
        $crawls = [];
        foreach ([[], ['title_missing']] as $rules) {
            $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
            $page = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://k.test/', 'url_hash' => sha1('https://k.test/'), 'state' => 'done', 'status_code' => 200]);
            foreach ($rules as $rule) {
                CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $page->id, 'rule' => $rule, 'severity' => 'high']);
            }
            $crawls[] = $crawl;
        }

        CrawlAlerts::newIssues($crawls[1]);
        $this->assertStringContainsString('1 sayfa', AlertEvent::where('kind', 'new_issue')->sole()->body);

        $stuck = Crawl::create(['project_id' => $project->id, 'status' => 'running', 'settings' => []]);
        Crawl::whereKey($stuck->id)->update(['updated_at' => now()->subHours(3)]);
        CrawlMaintenance::rescueStalled();
        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame(1, AlertEvent::where('kind', 'crawl_failed')->count());
    }

    public function test_search_console_drop_is_alerted_once_a_week(): void
    {
        User::factory()->create(['telegram_chat_id' => '42']);
        $project = Project::factory()->create(['name' => 'Klinik', 'gsc_property' => 'sc-domain:k.test']);
        foreach (range(0, 13) as $daysAgo) {
            DB::table('gsc_page_days')->insert(['project_id' => $project->id, 'day' => now()->subDays($daysAgo + 2)->toDateString(), 'page' => 'https://k.test/', 'page_hash' => sha1('https://k.test/'), 'clicks' => $daysAgo < 7 ? 5 : 20, 'impressions' => 100, 'position' => 5]);
        }

        $this->assertSame(1, GscDrop::runAll());
        $this->assertStringContainsString('%75', AlertEvent::where('kind', 'gsc_drop')->sole()->title);
        $this->assertSame(0, GscDrop::runAll(), 'not again the next day');
    }

    public function test_weekly_digest_lists_the_week(): void
    {
        Mail::fake();
        Setting::put('smtp_username', 'bildirim@example.com');
        User::factory()->create(['alert_email' => 'a@example.com']);
        User::factory()->create(['alert_email' => 'b@example.com', 'weekly_digest' => false]);
        Alerts::send('ssl', 'SSL bitiyor: Klinik', 'x', Project::factory()->create()->id);

        $this->assertSame(1, Notifier::weeklyDigest());
    }
}
