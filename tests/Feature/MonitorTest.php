<?php

namespace Tests\Feature;

use App\Events\MonitorAlert;
use App\Filament\Resources\Monitors\Pages\EditMonitor;
use App\Filament\Resources\Monitors\Pages\ListMonitors;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Project;
use App\Models\User;
use App\Monitoring\MonitorRunner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MonitorTest extends TestCase
{
    use RefreshDatabase;

    private function monitor(array $attributes = []): Monitor
    {
        $project = Project::factory()->create(['name' => 'Klinik', 'base_url' => 'http://site.test']);

        return tap($project->monitors()->first())->update($attributes);
    }

    public function test_new_project_gets_a_home_page_monitor(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://example.com']);

        $this->assertSame(['https://example.com'], $project->monitors()->pluck('url')->all());
    }

    public function test_down_opens_one_incident_and_recovery_closes_it(): void
    {
        Event::fake([MonitorAlert::class]);
        $monitor = $this->monitor();

        $siteStatus = 503;
        Http::fake(function ($request) use (&$siteStatus) {
            return str_contains($request->url(), 'site.test') ? Http::response('x', $siteStatus) : Http::response('', 204);
        });
        $this->assertSame(['checked' => 1, 'down' => 1, 'suppressed' => false], MonitorRunner::run(0));
        $this->assertSame('down', $monitor->fresh()->status);
        $this->assertSame('http', $monitor->openIncident()->cause);
        Event::assertDispatched(MonitorAlert::class, fn ($e) => str_starts_with($e->title, 'Erişilemiyor: Klinik'));

        // Still down a minute later: no second incident, no second alert.
        $this->travel(61)->seconds();
        MonitorRunner::run(0);
        $this->assertSame(1, $monitor->incidents()->count());
        Event::assertDispatchedTimes(MonitorAlert::class, 1);

        $this->travel(61)->seconds();
        $siteStatus = 200;
        MonitorRunner::run(0);
        $this->assertSame('up', $monitor->fresh()->status);
        $this->assertNull($monitor->openIncident());
        Event::assertDispatched(MonitorAlert::class, fn ($e) => str_starts_with($e->title, 'Düzeldi: Klinik'));
        $this->assertSame(3, MonitorCheck::count());
    }

    public function test_a_failure_that_passes_on_retry_is_not_an_incident(): void
    {
        $monitor = $this->monitor();
        Http::fakeSequence('site.test*')->push('', 500)->push('ok', 200);

        MonitorRunner::run(0);

        $this->assertSame('up', $monitor->fresh()->status);
        $this->assertSame(0, $monitor->incidents()->count());
    }

    public function test_our_own_outage_is_suppressed_by_canaries(): void
    {
        $monitor = $this->monitor();
        Http::fake(['*' => Http::response('', 503)]);

        $this->assertTrue(MonitorRunner::run(0)['suppressed']);
        $this->assertSame(0, $monitor->incidents()->count());
    }

    public function test_missing_keyword_counts_as_down(): void
    {
        Event::fake([MonitorAlert::class]);
        $monitor = $this->monitor(['keyword' => 'Randevu']);
        Http::fake(['site.test*' => Http::response('Hesap askıya alındı', 200), '*' => Http::response('', 204)]);

        MonitorRunner::run(0);

        $this->assertSame('keyword', $monitor->openIncident()->cause);
    }

    public function test_monitors_respect_their_interval(): void
    {
        $this->monitor(['interval_minutes' => 5, 'last_checked_at' => now()->subMinutes(2)]);
        Http::fake();

        $this->assertSame(0, MonitorRunner::run(0)['checked']);
    }

    public function test_monitors_run_before_the_queue_worker(): void
    {
        $names = collect(app(Schedule::class)->events())->pluck('description')->values();

        $this->assertLessThan($names->search('queue-worker'), $names->search('monitors'));
    }

    public function test_monitor_list_renders(): void
    {
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        $monitor = $this->monitor(['status' => 'up']);

        Livewire::test(ListMonitors::class)->assertCanSeeTableRecords([$monitor])->assertSee('Çalışıyor');
    }

    public function test_a_long_outage_sends_an_hourly_reminder(): void
    {
        Event::fake([MonitorAlert::class]);
        $this->monitor();
        Http::fake(fn ($request) => str_contains($request->url(), 'site.test') ? Http::response('', 503) : Http::response('', 204));

        MonitorRunner::run(0);
        $this->travel(30)->minutes();
        MonitorRunner::run(0);
        Event::assertDispatchedTimes(MonitorAlert::class, 1);

        $this->travel(31)->minutes();
        MonitorRunner::run(0);
        Event::assertDispatched(MonitorAlert::class, fn ($e) => str_starts_with($e->title, 'Hâlâ erişilemiyor: Klinik'));

        $this->travel(10)->minutes();
        MonitorRunner::run(0);
        Event::assertDispatchedTimes(MonitorAlert::class, 2);
    }

    public function test_daily_rollup_keeps_uptime_after_raw_checks_are_pruned(): void
    {
        $monitor = $this->monitor();
        $yesterday = now()->subDay()->setTime(12, 0);
        foreach ([true, true, true, false] as $i => $ok) {
            MonitorCheck::create(['monitor_id' => $monitor->id, 'checked_at' => $yesterday->copy()->addMinutes($i), 'ok' => $ok, 'response_ms' => 100]);
        }
        MonitorCheck::create(['monitor_id' => $monitor->id, 'checked_at' => now(), 'ok' => true, 'response_ms' => 80]);

        MonitorRunner::rollup($yesterday);
        MonitorCheck::query()->where('checked_at', '<', now()->startOfDay())->delete();

        $days = MonitorRunner::dailyUptime($monitor, 30);
        $this->assertCount(30, $days);
        $this->assertSame(75.0, $days[$yesterday->toDateString()]);
        $this->assertSame(100.0, $days[now()->toDateString()]);
        $this->assertNull($days[now()->subDays(5)->toDateString()]);
    }

    public function test_monitor_edit_page_shows_the_uptime_bar(): void
    {
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        $monitor = $this->monitor();
        MonitorCheck::create(['monitor_id' => $monitor->id, 'checked_at' => now(), 'ok' => true, 'response_ms' => 80]);

        Livewire::test(EditMonitor::class, ['record' => $monitor->getRouteKey()])->assertSee('Son 90 gün')->assertSee('%100');
    }
}
