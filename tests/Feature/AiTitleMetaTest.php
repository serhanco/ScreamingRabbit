<?php

namespace Tests\Feature;

use App\Ai\AiException;
use App\Ai\Driver;
use App\Ai\TitleMeta;
use App\Filament\Resources\Projects\Pages\ProjectWordPress;
use App\Jobs\ProposeTitleMeta;
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
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AiTitleMetaTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    /** Prompts the fake model received, and what it answers. */
    private array $prompts = [];

    private string|AiException $reply = '';

    private const URL = 'https://klinik.com/implant/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->project = Project::factory()->create(['base_url' => 'https://klinik.com', 'platform' => 'wordpress']);
        WpConnection::create(['project_id' => $this->project->id, 'secret' => 'gizli', 'site_url' => 'https://klinik.com/', 'connected_at' => now(), 'synced_at' => now(),
            'inventory' => ['core' => ['version' => '6.8'], 'settings' => ['writes_enabled' => true]]]);
        Http::fake(fn (Request $request) => Http::response(['post_id' => 7, 'seo_plugin' => 'yoast', 'writes_enabled' => true, 'redirect' => '', 'title' => '', 'description' => '', 'noindex' => '']));

        $crawl = Crawl::create(['project_id' => $this->project->id, 'status' => 'completed', 'settings' => [], 'finished_at' => now()]);
        CrawlPage::create(['crawl_id' => $crawl->id, 'url' => self::URL, 'url_hash' => sha1(self::URL), 'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html',
            'title' => 'Implant - Klinik', 'meta_description' => null, 'h1' => 'Diş implantı tedavisi', 'word_count' => 900]);
        DB::table('gsc_queries')->insert(['project_id' => $this->project->id, 'page_hash' => sha1(self::URL), 'page' => self::URL, 'query' => 'implant fiyatları', 'clicks' => 4, 'impressions' => 800, 'position' => 9.3]);

        Setting::put('ai_key_anthropic', 'sk-test');
        $test = $this;
        $this->app->instance('ai.driver.anthropic', new class($test) implements Driver
        {
            public function __construct(private AiTitleMetaTest $test) {}

            public function complete(string $model, string $system, string $prompt, int $maxTokens, string $tier, array $images = []): array
            {
                return $this->test->answer($prompt);
            }
        });
    }

    public function answer(string $prompt): array
    {
        $this->prompts[] = $prompt;
        if ($this->reply instanceof AiException) {
            throw $this->reply;
        }

        return ['text' => $this->reply, 'input_tokens' => 300, 'output_tokens' => 80, 'model' => 'claude-haiku-5-5'];
    }

    public function test_the_suggestion_uses_the_crawl_and_search_queries_and_waits_for_approval(): void
    {
        $this->reply = "İşte öneri:\n".json_encode(['title' => 'Diş İmplantı Tedavisi ve Fiyatları | Klinik', 'description' => 'Diş implantı tedavisinin aşamaları, süresi ve fiyatları hakkında bilmeniz gerekenler. Ücretsiz muayene için randevu alın.', 'reason' => 'En çok gösterim alan "implant fiyatları" sorgusu başlığa eklendi.']);

        $changes = TitleMeta::propose($this->project, self::URL, $this->admin);

        $this->assertCount(2, $changes);
        $this->assertStringContainsString('Diş implantı tedavisi', $this->prompts[0]);
        $this->assertStringContainsString('implant fiyatları | 4 | 800 | 9.3', $this->prompts[0]);
        $title = WpChange::where('field', 'title')->sole();
        $this->assertSame(['proposed', 'ai', 'Diş İmplantı Tedavisi ve Fiyatları | Klinik'], [$title->status, $title->source, $title->after]);
        $this->assertStringContainsString('implant fiyatları', $title->note);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'change'));
    }

    public function test_unreadable_answers_and_ai_errors_are_explained(): void
    {
        $this->reply = 'Üzgünüm, yardımcı olamam.';
        $this->assertSame('AI cevabı okunamadı; tekrar deneyin.', TitleMeta::propose($this->project, self::URL, $this->admin));

        $this->reply = new AiException('Anthropic: API anahtarı geçersiz.');
        $this->assertSame('Anthropic: API anahtarı geçersiz.', TitleMeta::propose($this->project, self::URL, $this->admin));
        $this->assertSame(0, WpChange::count());
    }

    public function test_the_page_action_proposes_and_a_proposal_can_be_edited(): void
    {
        $this->reply = json_encode(['title' => 'Diş İmplantı | Klinik', 'description' => 'Diş implantı hakkında her şey.', 'reason' => 'Kısa ve net.']);

        Livewire::actingAs($this->admin)->test(ProjectWordPress::class, ['record' => $this->project->id])
            ->callAction('aiTitleMeta', ['url' => self::URL])
            ->assertHasNoActionErrors()
            ->assertSee('AI önerisi (claude-haiku-5-5): Kısa ve net.');

        $title = WpChange::where('field', 'title')->sole();
        Livewire::actingAs($this->admin)->test(ProjectWordPress::class, ['record' => $this->project->id])
            ->callAction('editChange', ['after' => 'Diş İmplantı Tedavisi | Klinik'], ['change' => $title->id])
            ->assertSee('Diş İmplantı Tedavisi | Klinik');
        $this->assertSame('Diş İmplantı Tedavisi | Klinik', $title->fresh()->after);
    }

    public function test_bulk_suggestions_start_with_the_most_seen_pages_and_can_be_approved_together(): void
    {
        $crawl = Crawl::sole();
        $make = function (string $path, int $impressions, bool $issue) use ($crawl): string {
            $url = 'https://klinik.com'.$path;
            $page = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => $url, 'url_hash' => sha1($url), 'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'title' => 'Sayfa']);
            if ($issue) {
                DB::table('crawl_issues')->insert(['crawl_id' => $crawl->id, 'page_id' => $page->id, 'rule' => 'meta_description_missing', 'severity' => 'medium']);
            }
            DB::table('gsc_page_days')->insert(['project_id' => $this->project->id, 'day' => now()->subDays(2)->toDateString(), 'page' => $url, 'page_hash' => sha1($url),
                'clicks' => 1, 'impressions' => $impressions, 'position' => 5]);

            return $url;
        };
        $quiet = $make('/az/', 10, true);
        $busy = $make('/cok/', 5000, true);
        $make('/sorunsuz/', 9000, false);
        $this->assertSame([$busy, $quiet], TitleMeta::candidates($this->project, 10));

        Queue::fake();
        $this->reply = json_encode(['title' => 'Yeni başlık', 'description' => 'Yeni açıklama', 'reason' => 'Gösterim alan sorgular.']);
        Livewire::actingAs($this->admin)->test(ProjectWordPress::class, ['record' => $this->project->id])
            ->callAction('aiBulk', ['count' => 5])
            ->assertNotified('Öneriler hazırlanıyor');
        $this->assertSame([$busy], WpChange::distinct()->pluck('url')->all(), 'the first page is done at once');
        Queue::assertPushed(ProposeTitleMeta::class, fn (ProposeTitleMeta $job) => $job->url === $quiet);
        $this->assertSame([$quiet], TitleMeta::candidates($this->project, 10), 'pages with a waiting suggestion are skipped');

        Http::fake(fn (Request $request) => str_contains($request->url(), 'meta')
            ? Http::response(['post_id' => 7, 'seo_plugin' => 'yoast', 'writes_enabled' => true, 'redirect' => '', 'title' => '', 'description' => '', 'noindex' => ''])
            : Http::response(['before' => '', 'after' => 'x']));
        Livewire::actingAs($this->admin)->test(ProjectWordPress::class, ['record' => $this->project->id])
            ->callAction('applyAll')
            ->assertNotified('2 değişiklik siteye yazıldı');
        $this->assertSame(0, WpChange::where('status', 'proposed')->count());
    }
}
