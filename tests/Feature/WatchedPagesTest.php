<?php

namespace Tests\Feature;

use App\Events\MonitorAlert;
use App\Filament\Resources\WatchedPages\Pages\CreateWatchedPage;
use App\Filament\Resources\WatchedPages\Pages\EditWatchedPage;
use App\Filament\Resources\WatchedPages\Pages\ListWatchedPages;
use App\Models\Project;
use App\Models\User;
use App\Models\WatchedPage;
use App\Monitoring\PageWatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WatchedPagesTest extends TestCase
{
    use RefreshDatabase;

    private function html(string $title, string $robots = 'index'): string
    {
        return '<html><head><title>'.$title.'</title><meta name="robots" content="'.$robots.'"><link rel="canonical" href="https://site.test/hizmet"></head><body>x</body></html>';
    }

    public function test_change_is_confirmed_recorded_and_alerted(): void
    {
        Event::fake([MonitorAlert::class]);
        $project = Project::factory()->create(['name' => 'Klinik']);
        $page = WatchedPage::create(['project_id' => $project->id, 'url' => 'https://site.test/hizmet']);

        // One fake for the whole test (later fakes would not replace it); $responses decides each fetch.
        $body = $this->html('Diş beyazlatma');
        $responses = [];
        Http::fake(function () use (&$responses, &$body) {
            return array_shift($responses) ?? Http::response($body, 200, ['Content-Type' => 'text/html']);
        });
        $this->assertSame(1, PageWatcher::runDue());
        $this->assertSame(['status' => 200, 'location' => null, 'noindex' => false, 'canonical' => 'https://site.test/hizmet', 'title' => 'Diş beyazlatma'], $page->fresh()->state);
        Event::assertNotDispatched(MonitorAlert::class);

        // Not due again within the hour.
        $this->travel(30)->minutes();
        $this->assertSame(0, PageWatcher::runDue());

        // A one-off blip: the confirming second fetch is back to normal, so nothing is recorded.
        $this->travel(31)->minutes();
        $responses = [Http::response('', 503)];
        PageWatcher::runDue();
        $this->assertSame(0, $page->changes()->count());
        Event::assertNotDispatched(MonitorAlert::class);

        // A real change: noindex added and the title changed.
        $this->travel(61)->minutes();
        $body = $this->html('Yeni başlık', 'noindex, follow');
        PageWatcher::runDue();
        $this->assertEqualsCanonicalizing(['noindex', 'title'], $page->changes()->pluck('field')->all());
        $this->assertNotNull($page->fresh()->last_changed_at);
        Event::assertDispatched(MonitorAlert::class, fn ($e) => $e->kind === 'page_change' && $e->projectId === $project->id
            && str_contains($e->body, 'Noindex: yok → var') && str_contains($e->body, 'Title: Diş beyazlatma → Yeni başlık'));
    }

    public function test_redirect_is_read_without_following_it(): void
    {
        Http::fake(fn () => Http::response('', 301, ['Location' => 'https://site.test/yeni']));
        $page = WatchedPage::create(['project_id' => Project::factory()->create()->id, 'url' => 'https://site.test/eski', 'state' => ['status' => 200, 'location' => null, 'noindex' => false, 'canonical' => null, 'title' => 'Eski']]);
        Event::fake([MonitorAlert::class]);

        PageWatcher::runDue();

        $this->assertSame(['status', 'location', 'title'], $page->changes()->orderBy('id')->pluck('field')->all());
        $this->assertSame('https://site.test/yeni', $page->changes()->where('field', 'location')->value('new_value'));
    }

    public function test_panel_lists_creates_and_limits_pages(): void
    {
        $project = Project::factory()->create(['name' => 'Klinik']);
        WatchedPage::create(['project_id' => $project->id, 'url' => 'https://site.test/', 'state' => ['status' => 200, 'title' => 'Ana sayfa', 'noindex' => false], 'last_changed_at' => now()]);
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));

        Livewire::test(ListWatchedPages::class)->assertOk()->assertSee('Ana sayfa');
        Livewire::test(CreateWatchedPage::class)
            ->fillForm(['project_id' => $project->id, 'url' => 'https://site.test/iletisim', 'frequency' => 'daily'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('daily', WatchedPage::where('url', 'https://site.test/iletisim')->value('frequency'));

        for ($i = WatchedPage::count(); $i < WatchedPage::MAX_PER_PROJECT; $i++) {
            WatchedPage::create(['project_id' => $project->id, 'url' => 'https://site.test/p'.$i]);
        }
        Livewire::test(CreateWatchedPage::class)
            ->fillForm(['project_id' => $project->id, 'url' => 'https://site.test/fazla'])
            ->call('create')->assertHasFormErrors(['url']);

        Livewire::test(EditWatchedPage::class, ['record' => WatchedPage::first()->getKey()])->assertOk()->assertSee('Değişiklik geçmişi');
    }
}
