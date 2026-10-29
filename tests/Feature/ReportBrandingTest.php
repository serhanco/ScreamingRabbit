<?php

namespace Tests\Feature;

use App\Filament\Pages\ReportSettings;
use App\Models\Crawl;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Reports\CrawlReport;
use App\Reports\ReportBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ReportBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_primary_admin_brands_client_reports(): void
    {
        $admin = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->assertTrue($admin->fresh()->isPrimary());
        $this->actingAs($admin);

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>');
        Livewire::test(ReportSettings::class)
            ->fillForm(['report_company' => 'Hekim Dijital', 'report_accent' => '#0f766e', 'report_contact' => 'info@hekimdijital.com', 'report_intro' => 'Merhaba, bu ayın raporu.', 'logo_upload' => $svg])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertStringStartsWith('data:image/svg+xml;base64,', Setting::get('report_logo'));
        $branding = ReportBranding::current();
        $this->assertSame('#0f766e', $branding['accent']);
        $this->assertSame('#0d645e', $branding['link']);

        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'finished_at' => now()]);
        $html = (new CrawlReport($crawl))->html();
        $this->assertStringContainsString('Hekim Dijital', $html);
        $this->assertStringContainsString('info@hekimdijital.com', $html);
        $this->assertStringContainsString('Merhaba, bu ayın raporu.', $html);
        $this->assertStringContainsString('border-bottom: 2px solid #0f766e', $html);
        $this->assertStringContainsString(Setting::get('report_logo'), $html);
        $this->assertStringContainsString('Bir <a href="'.config('screamingrabbit.brand.url').'">', $html, 'the product attribution stays');
    }

    public function test_only_the_primary_admin_sees_the_page(): void
    {
        User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $second = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($second)->get(ReportSettings::getUrl())->assertForbidden();
        $this->assertSame(ReportBranding::DEFAULT_ACCENT, ReportBranding::current()['accent']);
    }
}
