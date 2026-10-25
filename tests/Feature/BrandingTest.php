<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Palette;
use Filament\Facades\Filament;
use Filament\Livewire\Topbar;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP', ...$attributes]);
    }

    public function test_login_page_shows_logo_and_favicon(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('images/logo-mark.svg', false)
            ->assertSee('favicon.svg', false);
    }

    public function test_unknown_palette_falls_back_to_default(): void
    {
        $this->assertSame('turuncu', Palette::resolve('nope'));
        $this->assertSame('turuncu', Palette::resolve(null));
        $this->assertSame(Color::Orange, Palette::colors(null)['primary']);
        $this->assertSame(Color::Sky, Palette::colors('okyanus')['primary']);
    }

    public function test_every_configured_palette_uses_real_filament_colours(): void
    {
        foreach (array_keys(Palette::options()) as $key) {
            $colors = Palette::colors($key);
            $this->assertIsArray($colors['primary'], $key);
            $this->assertIsArray($colors['gray'], $key);
        }
    }

    public function test_user_palette_is_applied_to_the_panel(): void
    {
        $user = $this->user(['color_palette' => 'orman']);

        $this->actingAs($user)->get('/admin')
            ->assertOk()
            ->assertSee(Color::Emerald[500], false);
    }

    public function test_user_can_switch_palette_from_the_user_menu(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');

        Livewire::test(Topbar::class)
            ->callAction('palette', ['palette' => 'mor'])
            ->assertRedirect();

        $this->assertSame('mor', $user->fresh()->color_palette);
    }

    public function test_report_shows_logo_in_web_and_pdf(): void
    {
        $html = view('reports.crawl', ['report' => [
            'project' => 'P', 'client' => 'C', 'site' => 'https://example.com', 'date' => '25.10.2026', 'score' => 90,
            'crawled' => 1, 'ok' => 1, 'redirects' => 0, 'client_errors' => 0, 'server_errors' => 0,
            'by_severity' => [], 'findings' => [], 'brand' => config('screamingrabbit.brand'),
        ], 'forPdf' => true])->render();

        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('ScreamingRabbit', strip_tags($html));
    }
}
