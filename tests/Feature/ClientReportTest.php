<?php

namespace Tests\Feature;

use App\Models\Crawl;
use App\Models\CrawlIssue;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientReportTest extends TestCase
{
    use RefreshDatabase;

    private function crawl(string $status = 'completed', ?string $token = 'tok123'): Crawl
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => $status, 'settings' => [], 'share_token' => $token]);
        $page = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/şç', 'url_hash' => sha1('x'), 'state' => 'done', 'status_code' => 404]);
        CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $page->id, 'rule' => 'http_4xx', 'severity' => 'high', 'detail' => 'HTTP 404']);

        return $crawl;
    }

    public function test_shared_report_shows_brand_and_is_noindex(): void
    {
        $this->crawl();

        $this->get('/rapor/tok123')
            ->assertOk()
            ->assertSee('https://serhandemirel.com', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_pdf_is_served(): void
    {
        $this->crawl();

        $response = $this->get('/rapor/tok123/pdf')->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_report_has_ai_visibility_and_wordpress_sections(): void
    {
        $crawl = $this->crawl();
        $crawl->update([
            'bot_access' => [
                ['token' => 'googlebot', 'name' => 'Googlebot', 'kind' => 'search', 'robots_allowed' => true, 'fetch' => 'ok', 'status' => 200],
                ['token' => 'oai-searchbot', 'name' => 'OAI-SearchBot', 'kind' => 'ai_search', 'robots_allowed' => false, 'fetch' => 'ok', 'status' => 200],
            ],
            'llms_txt' => ['status' => 'missing'],
            'wp_checks' => [
                'inventory' => ['plugins' => ['contact-form-7' => '5.1'], 'themes' => ['astra' => null]],
                'probes' => ['feed' => ['value' => '6.4.2'], 'core_latest' => ['value' => '6.8.3'], 'plugin:contact-form-7' => ['value' => '6.1']],
            ],
        ]);
        $home = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/', 'url_hash' => sha1('home'), 'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'depth' => 0, 'schema_types' => 'WebSite']);
        CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $home->id, 'rule' => 'wp_core_outdated', 'severity' => 'high']);

        $this->get('/rapor/tok123')
            ->assertOk()
            ->assertSee('AI arama görünürlüğü')
            ->assertSee('0 / 1 erişebiliyor')
            ->assertSee('%100 (1 / 1)')
            ->assertSee('WordPress')
            ->assertSee('6.4.2')
            ->assertSee('contact-form-7')
            ->assertSee('Sorun var, ayrıntı aşağıda');

        $this->get('/rapor/tok123/pdf')->assertOk();
    }

    public function test_unknown_revoked_or_unfinished_is_404(): void
    {
        $this->get('/rapor/nope')->assertNotFound();
        $this->crawl('running', 'run1');
        $this->get('/rapor/run1')->assertNotFound();
        Crawl::query()->update(['share_token' => null]);
        $this->get('/rapor/tok123')->assertNotFound();
    }

    public function test_login_page_has_brand_footer(): void
    {
        $this->get('/admin/login')->assertSee('serhandemirel.com', false);
    }
}
