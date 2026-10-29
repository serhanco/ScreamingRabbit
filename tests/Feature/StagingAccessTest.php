<?php

namespace Tests\Feature;

use App\Crawler\CrawlRunner;
use App\Models\ActivityLog;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StagingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_site_is_crawled_without_leaking_access_to_other_hosts(): void
    {
        $secret = 'staging-'.'pass';
        Http::fake(function (Request $request) use ($secret) {
            $own = str_contains($request->url(), 'staging.example.com');
            if ($own && ($request->header('Authorization')[0] ?? '') !== 'Basic '.base64_encode('serhan:'.$secret)) {
                return Http::response('Unauthorized', 401);
            }
            if (str_ends_with($request->url(), 'staging.example.com/')) {
                return Http::response('<html><body><a href="/b">b</a><a href="https://other.com/x">x</a></body></html>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('<html><body>ok</body></html>', $own && str_ends_with($request->url(), '/b') ? 200 : 404, ['Content-Type' => 'text/html']);
        });

        $project = Project::factory()->create(['base_url' => 'https://staging.example.com', 'crawl_settings' => ['delay_ms' => 0],
            'crawl_auth' => ['user' => 'serhan', 'password' => $secret, 'headers' => [['name' => 'X-Staging', 'value' => '1'], ['name' => 'Host', 'value' => 'evil']]]]);
        $this->assertStringNotContainsString($secret, (string) DB::table('projects')->value('crawl_auth'), 'stored encrypted');

        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);
        while ((new CrawlRunner($crawl->fresh()))->run()) {
        }

        $this->assertSame(200, CrawlPage::where('url', 'https://staging.example.com/b')->value('status_code'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'staging.example.com') && $request->header('X-Staging') === ['1'] && $request->header('Host') !== ['evil']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'other.com') && ($request->hasHeader('Authorization') || $request->hasHeader('X-Staging')));
    }

    public function test_access_is_masked_in_the_activity_log(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->create();
        $project->update(['crawl_auth' => ['user' => 'a', 'password' => 'secret-value', 'headers' => []]]);

        $log = ActivityLog::where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(['old' => '••••', 'new' => '••••'], $log->changes['crawl_auth']);
    }
}
