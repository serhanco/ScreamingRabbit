<?php

namespace Tests\Feature;

use App\Events\MonitorAlert;
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
}
