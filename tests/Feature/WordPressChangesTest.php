<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ProjectWordPress;
use App\Models\Project;
use App\Models\User;
use App\Models\WpChange;
use App\Models\WpConnection;
use App\WordPress\Changes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WordPressChangesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->project = Project::factory()->create(['base_url' => 'https://klinik.com', 'platform' => 'wordpress']);
        WpConnection::create(['project_id' => $this->project->id, 'secret' => 'gizli', 'site_url' => 'https://klinik.com/', 'connected_at' => now(), 'synced_at' => now(),
            'inventory' => ['core' => ['version' => '6.8'], 'settings' => ['writes_enabled' => true]]]);
    }

    /** What the plugin's /change route answers next. */
    private array $answer = [];

    private function fakeSite(array $body, int $status = 200): void
    {
        $this->answer = [$body, $status];
        Http::fake(fn (Request $request) => str_contains($request->url(), 'meta')
            ? Http::response(['post_id' => 12, 'seo_plugin' => 'yoast', 'writes_enabled' => true, 'title' => 'Eski başlık', 'description' => '', 'noindex' => ''])
            : Http::response(...$this->answer));
    }

    public function test_a_change_is_proposed_applied_and_reverted(): void
    {
        $this->fakeSite(['post_id' => 12, 'before' => 'Eski başlık', 'after' => 'Yeni başlık']);

        $change = Changes::propose($this->project, 'https://klinik.com/hizmet/', 'title', 'Yeni başlık', $this->admin);
        $this->assertInstanceOf(WpChange::class, $change);
        $this->assertSame(['proposed', 'Eski başlık', 12], [$change->status, $change->before, $change->post_id]);
        Http::assertSentCount(1);

        Changes::apply($change, $this->admin);
        $this->assertSame('applied', $change->fresh()->status);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'change') && $request->data()['before'] === 'Eski başlık'
            && $request->data()['after'] === 'Yeni başlık' && $request->hasHeader('X-SR-Signature'));

        $this->fakeSite(['post_id' => 12, 'before' => 'Yeni başlık', 'after' => 'Eski başlık']);
        $reverse = Changes::revert($change->fresh(), $this->admin);
        $this->assertSame(['applied', 'Yeni başlık', 'Eski başlık'], [$reverse->status, $reverse->before, $reverse->after]);
        $this->assertSame('reverted', $change->fresh()->status);
    }

    public function test_conflicts_and_disabled_writes_are_explained(): void
    {
        $this->fakeSite(['code' => 'sr_conflict', 'message' => 'x', 'data' => ['status' => 409, 'current' => 'Başkası değiştirdi']], 409);
        $change = Changes::propose($this->project, 'https://klinik.com/hizmet/', 'noindex', 'noindex', $this->admin);
        Changes::apply($change, $this->admin);
        $this->assertSame('conflict', $change->fresh()->status);
        $this->assertStringContainsString('Başkası değiştirdi', $change->fresh()->error);

        $this->fakeSite(['code' => 'sr_writes_disabled', 'message' => 'x', 'data' => ['status' => 403]], 403);
        $change = Changes::propose($this->project, 'https://klinik.com/hizmet/', 'description', 'Açıklama', $this->admin);
        Changes::apply($change, $this->admin);
        $this->assertSame('failed', $change->fresh()->status);
        $this->assertStringContainsString('Değişiklik izni', $change->fresh()->error);

        $this->assertSame('Yeni değer sitedekiyle aynı.', Changes::propose($this->project, 'https://klinik.com/hizmet/', 'title', 'Eski başlık', $this->admin));
    }

    public function test_page_actions_propose_and_apply(): void
    {
        $this->fakeSite(['post_id' => 12, 'before' => 'Eski başlık', 'after' => 'Yeni başlık']);

        Livewire::actingAs($this->admin)->test(ProjectWordPress::class, ['record' => $this->project->id])
            ->callAction('propose', ['url' => 'https://klinik.com/hizmet/', 'field' => 'title', 'after' => 'Yeni başlık'])
            ->assertHasNoActionErrors()
            ->assertSee('Onay bekliyor');

        $change = WpChange::sole();
        Livewire::actingAs($this->admin)->test(ProjectWordPress::class, ['record' => $this->project->id])
            ->callAction('applyChange', arguments: ['change' => $change->id])
            ->assertSee('Uygulandı');
        $this->assertSame($this->admin->id, $change->fresh()->approved_by);
    }
}
