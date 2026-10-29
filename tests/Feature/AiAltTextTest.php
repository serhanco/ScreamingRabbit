<?php

namespace Tests\Feature;

use App\Ai\AltText;
use App\Ai\Driver;
use App\Filament\Resources\Projects\Pages\ProjectWordPress;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Models\WpChange;
use App\Models\WpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AiAltTextTest extends TestCase
{
    use RefreshDatabase;

    private const IMAGE = 'https://klinik.com/wp-content/uploads/implant.jpg';

    private Project $project;

    private User $admin;

    /** What the fake model was shown. */
    public array $seen = [];

    private string $imageType = 'image/jpeg';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->project = Project::factory()->create(['base_url' => 'https://klinik.com', 'platform' => 'wordpress']);
        WpConnection::create(['project_id' => $this->project->id, 'secret' => 'gizli', 'site_url' => 'https://klinik.com/', 'connected_at' => now(), 'synced_at' => now(),
            'inventory' => ['core' => ['version' => '6.8'], 'settings' => ['writes_enabled' => true]]]);
        Http::fake(fn (Request $request) => str_contains($request->url(), 'rest_route')
            ? Http::response(['attachment_id' => 31, 'seo_plugin' => 'yoast', 'writes_enabled' => true, 'redirect' => '', 'alt' => ''])
            : Http::response('JPEGDATA', 200, ['Content-Type' => $this->imageType]));

        $crawl = Crawl::create(['project_id' => $this->project->id, 'status' => 'completed', 'settings' => [], 'finished_at' => now()]);
        $page = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://klinik.com/implant/', 'url_hash' => sha1('https://klinik.com/implant/'), 'state' => 'done',
            'status_code' => 200, 'content_type' => 'text/html', 'title' => 'Implant tedavisi', 'h1' => 'Diş implantı']);
        $image = DB::table('crawl_images')->insertGetId(['crawl_id' => $crawl->id, 'url' => self::IMAGE, 'url_hash' => sha1(self::IMAGE), 'state' => 'done']);
        DB::table('crawl_image_refs')->insert(['crawl_id' => $crawl->id, 'image_id' => $image, 'page_id' => $page->id]);

        Setting::put('ai_key_anthropic', 'sk-test');
        $test = $this;
        $this->app->instance('ai.driver.anthropic', new class($test) implements Driver
        {
            public function __construct(private AiAltTextTest $test) {}

            public function complete(string $model, string $system, string $prompt, int $maxTokens, string $tier, array $images = []): array
            {
                $this->test->seen[] = compact('prompt', 'images');

                return ['text' => '{"alt": "Çene kemiğine yerleştirilmiş titanyum diş implantı modeli", "reason": "Görseldeki implant modeli ve sayfa başlığı."}',
                    'input_tokens' => 1500, 'output_tokens' => 40, 'model' => 'claude-haiku-5-5'];
            }
        });
    }

    public function test_the_model_sees_the_image_and_its_page_and_the_alt_waits_for_approval(): void
    {
        $change = AltText::propose($this->project, self::IMAGE, $this->admin);

        $this->assertInstanceOf(WpChange::class, $change);
        $this->assertSame(['alt', 'proposed', 'ai', 'Çene kemiğine yerleştirilmiş titanyum diş implantı modeli', 31], [$change->field, $change->status, $change->source, $change->after, $change->post_id]);
        $this->assertSame([['mime' => 'image/jpeg', 'data' => base64_encode('JPEGDATA')]], $this->seen[0]['images']);
        $this->assertStringContainsString('Used on: https://klinik.com/implant/ | title: Implant tedavisi | H1: Diş implantı', $this->seen[0]['prompt']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'change'));
    }

    public function test_other_sites_and_unreadable_files_are_refused(): void
    {
        $this->assertSame('Görsel bu sitede olmalı.', AltText::propose($this->project, 'https://baska.com/a.jpg', $this->admin));

        $this->imageType = 'image/svg+xml';
        $this->assertStringContainsString('JPEG, PNG, GIF veya WebP', AltText::propose($this->project, self::IMAGE, $this->admin));
        $this->assertSame([], $this->seen);
    }

    public function test_the_page_action(): void
    {
        Livewire::actingAs($this->admin)->test(ProjectWordPress::class, ['record' => $this->project->id])
            ->callAction('aiAlt', ['url' => self::IMAGE])
            ->assertHasNoActionErrors()
            ->assertSee('Çene kemiğine yerleştirilmiş titanyum diş implantı modeli');
    }
}
