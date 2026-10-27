<?php

namespace Tests\Feature;

use App\Filament\Pages\GoogleSettings;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Widgets\GoogleSetupGuide;
use App\Filament\Resources\Crawls\Pages\ViewCrawl;
use App\Filament\Resources\Crawls\RelationManagers\PagesRelationManager;
use App\Google\GoogleClient;
use App\Google\GscSync;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\GoogleConnection;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GscTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        Setting::put('google_client_id', 'client-123');
        Setting::put('google_client_secret', str_repeat('s', 12));
    }

    private function connect(?User $user = null, string $token = 'refresh-1'): void
    {
        GoogleConnection::create(['user_id' => ($user ?? auth()->user())->id, 'email' => 'serhan@example.com', 'refresh_token' => $token]);
    }

    public function test_settings_page_shows_redirect_uri_and_connect_button(): void
    {
        Livewire::test(GoogleSettings::class)
            ->assertSee(GoogleClient::redirectUri())
            ->assertSee('Google hesabını bağla');
    }

    public function test_dashboard_shows_setup_guide_until_google_is_connected(): void
    {
        $this->get('/admin')->assertOk()->assertSee('Başlarken: Google Search Console bağlantısı')->assertSee(GoogleClient::redirectUri());

        $this->connect();
        $this->get('/admin')->assertOk()->assertDontSee('Başlarken: Google Search Console bağlantısı');
    }

    public function test_dashboard_guide_saves_the_keys(): void
    {
        Setting::put('google_client_id', null);
        Setting::put('google_client_secret', null);

        Livewire::test(GoogleSetupGuide::class)
            ->assertSee('Adım 1/7')
            ->assertSee('Bu düğme 6. adımda')
            ->set('clientId', ' id-1.apps.googleusercontent.com ')
            ->set('clientSecret', 'secret-1')
            ->call('saveKeys')
            ->assertHasNoErrors()
            ->assertSee(route('filament.admin.google.connect'));

        $this->assertSame('id-1.apps.googleusercontent.com', Setting::get('google_client_id'));
        $this->assertTrue(GoogleClient::configured());
    }

    public function test_settings_page_includes_setup_guide(): void
    {
        Livewire::test(GoogleSettings::class)->assertSee('Kurulum rehberi')->assertSee('Uygulamayı yayınla');
    }

    public function test_oauth_callback_stores_the_refresh_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3599]),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'serhan@example.com']),
        ]);

        $redirect = $this->get(route('filament.admin.google.connect'))->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('access_type=offline', $redirect);
        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);

        $this->get(route('filament.admin.google.callback', ['state' => 'wrong', 'code' => 'abc']))->assertRedirect(GoogleSettings::getUrl());
        $this->assertFalse(GoogleClient::connected(auth()->user()));

        $this->withSession(['google_oauth_state' => $query['state']])
            ->get(route('filament.admin.google.callback', ['state' => $query['state'], 'code' => 'abc']))
            ->assertRedirect(GoogleSettings::getUrl());

        $this->assertTrue(GoogleClient::connected(auth()->user()));
        $this->assertSame('serhan@example.com', GoogleClient::connectionOf(auth()->user())->email);
        $this->assertSame('refresh-1', GoogleClient::connectionOf(auth()->user())->refresh_token);
        $this->assertNotSame('refresh-1', DB::table('google_connections')->value('refresh_token'), 'stored encrypted');
    }

    /** Each refresh token gets its own access token, and each access token its own site list. */
    private function fakeGoogleAccounts(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => fn ($request) => Http::response(['access_token' => 'access-'.$request['refresh_token'], 'expires_in' => 3599]),
            'www.googleapis.com/webmasters/v3/sites' => fn ($request) => Http::response(['siteEntry' => $request->hasHeader('Authorization', 'Bearer access-refresh-1')
                ? [['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner'], ['siteUrl' => 'sc-domain:gizli.com', 'permissionLevel' => 'siteOwner']]
                : [['siteUrl' => 'sc-domain:ikinci.com', 'permissionLevel' => 'siteOwner']]]),
            'www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => []]),
        ]);
    }

    public function test_each_admin_sees_only_their_own_google_site_list(): void
    {
        $this->connect();
        $this->fakeGoogleAccounts();
        $serhan = auth()->user();
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'gsc_property' => 'sc-domain:example.com']);
        $this->assertSame($serhan->id, $project->gsc_user_id);

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->assertSee('gizli.com')
            ->assertFormFieldEnabled('gsc_property');

        // A second admin assigned to the project, before connecting Google: no site list, the property is read-only.
        $other = User::factory()->create(['role' => 'admin', 'app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $other->projects()->attach($project);
        $this->actingAs($other);
        $this->assertFalse($other->isPrimary());
        $this->assertTrue(GoogleSettings::canAccess(), 'every admin may connect their own account');
        $this->get(GoogleSettings::getUrl())->assertOk()->assertDontSee('serhan@example.com')->assertDontSee('İstemci kimliği (Client ID)');

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->assertDontSee('gizli.com')
            ->assertFormFieldDisabled('gsc_property')
            ->fillForm(['gsc_property' => 'sc-domain:gizli.com'])
            ->call('save');
        $this->assertSame('sc-domain:example.com', $project->fresh()->gsc_property);

        // After connecting: their own list only, never Serhan's.
        $this->connect($other, 'refresh-2');
        $this->assertSame(['sc-domain:ikinci.com' => 'ikinci.com (alan adı)'], GoogleClient::sites($other));
        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->assertDontSee('gizli.com')
            ->assertSee('ikinci.com')
            ->assertFormFieldEnabled('gsc_property')
            ->fillForm(['gsc_property' => 'sc-domain:gizli.com'])
            ->call('save')
            ->assertHasFormErrors(['gsc_property']);

        // Picking from their own list moves the project to their connection.
        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->fillForm(['gsc_property' => 'sc-domain:ikinci.com'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($other->id, $project->fresh()->gsc_user_id);
        GscSync::run($project->fresh());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'searchAnalytics') && $request->hasHeader('Authorization', 'Bearer access-refresh-2'));
        $this->assertSame(1, GoogleClient::connectionOf($other)->callsToday());
        $this->assertSame(0, GoogleClient::connectionOf($serhan)->callsToday(), 'quota is counted per connection');
    }

    public function test_invited_members_never_connect_google_or_see_the_guide(): void
    {
        $this->connect();
        $project = Project::factory()->create(['gsc_property' => 'sc-domain:example.com']);
        $specialist = User::factory()->create(['role' => 'specialist', 'app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $specialist->projects()->attach($project);
        $this->actingAs($specialist);

        $this->assertFalse(GoogleSettings::canAccess());
        $this->get(GoogleSettings::getUrl())->assertForbidden();
        $this->get(route('filament.admin.google.connect'))->assertForbidden();
        $this->get(route('filament.admin.google.callback', ['state' => 'x', 'code' => 'abc']))->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee('Başlarken: Google Search Console bağlantısı');
        $this->assertFalse(GoogleSetupGuide::canView());

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->assertFormFieldDisabled('gsc_property')
            ->assertSee('proje sahibinin Google bağlantısıyla');
    }

    public function test_second_admin_gets_only_the_connect_step_and_cannot_change_the_shared_key(): void
    {
        $this->connect();
        $other = User::factory()->create(['role' => 'admin', 'app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($other);

        $this->get('/admin')->assertOk()->assertSee('Başlarken: Google Search Console bağlantısı')->assertSee('Adım 1/1')->assertDontSee('Uygulamayı yayınla');
        Livewire::test(GoogleSetupGuide::class)->set('clientId', 'x')->set('clientSecret', 'y')->call('saveKeys')->assertForbidden();
        Livewire::test(GoogleSettings::class)->call('save')->assertForbidden();
        $this->assertSame('client-123', Setting::get('google_client_id'));
    }

    public function test_sites_and_sync_fill_page_metrics(): void
    {
        $this->connect();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3599]),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [
                ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner'],
                ['siteUrl' => 'https://other.com/', 'permissionLevel' => 'siteUnverifiedUser'],
            ]]),
            'www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => [
                ['keys' => [now()->subDays(3)->toDateString(), 'https://example.com/a'], 'clicks' => 7, 'impressions' => 100, 'position' => 4.5],
                ['keys' => [now()->subDays(4)->toDateString(), 'https://example.com/a'], 'clicks' => 3, 'impressions' => 100, 'position' => 6.5],
            ]]),
        ]);

        $this->assertSame(['sc-domain:example.com' => 'example.com (alan adı)'], GoogleClient::sites(auth()->user()));

        $project = Project::factory()->create(['base_url' => 'https://example.com', 'gsc_property' => 'sc-domain:example.com']);
        $this->assertSame(2, GscSync::run($project));
        $this->assertSame(2, GscSync::run($project), 'a rerun updates the same rows');
        $this->assertSame(2, DB::table('gsc_page_days')->count());
        Http::assertSent(fn ($request) => str_contains($request->url(), rawurlencode('sc-domain:example.com')) && $request['dimensions'] === ['date', 'page']);

        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/a', 'url_hash' => sha1('https://example.com/a'), 'state' => 'done', 'status_code' => 200]);

        $row = GscSync::last28($project->id)->first();
        $this->assertSame(10, (int) $row->gsc_clicks);
        $this->assertEqualsWithDelta(5.5, (float) $row->gsc_position, 0.01);

        Livewire::test(PagesRelationManager::class, ['ownerRecord' => $crawl, 'pageClass' => ViewCrawl::class])
            ->assertCanSeeTableRecords([$page])
            ->assertSee('Tıklama (28 g)')
            ->assertSee('%5,0');
    }

    public function test_queries_are_synced_and_shown_in_page_detail(): void
    {
        $this->connect();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3599]),
            'www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => [
                ['keys' => ['https://example.com/a', 'diş beyazlatma'], 'clicks' => 12, 'impressions' => 300, 'position' => 3.2],
                ['keys' => ['https://example.com/a', 'diş beyazlatma fiyat'], 'clicks' => 2, 'impressions' => 90, 'position' => 9.7],
            ]]),
        ]);
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'gsc_property' => 'sc-domain:example.com']);

        GscSync::runAll();
        $this->assertSame(2, DB::table('gsc_queries')->where('project_id', $project->id)->count());
        $this->assertTrue($project->fresh()->gsc_queries_synced_on->isToday());

        GscSync::syncQueries($project);
        $this->assertSame(2, DB::table('gsc_queries')->count(), 'a resync replaces the rows');

        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/a', 'url_hash' => sha1('https://example.com/a'), 'state' => 'done', 'status_code' => 200]);

        $html = view('crawls.page-detail', ['detail' => \App\Filament\Resources\Crawls\Support\PageDetail::for($page)])->render();
        $this->assertStringContainsString('diş beyazlatma fiyat', $html);
        $this->assertStringContainsString('3,2', $html);
    }

    public function test_url_inspection_checks_top_pages_and_shows_google_view(): void
    {
        $this->connect();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3599]),
            'searchconsole.googleapis.com/v1/urlInspection/index:inspect' => fn ($request) => str_ends_with($request['inspectionUrl'], '/b')
                ? Http::response(['inspectionResult' => ['indexStatusResult' => ['verdict' => 'NEUTRAL', 'coverageState' => 'Taranmış - şu anda dizine eklenmemiş', 'googleCanonical' => null]]])
                : Http::response(['inspectionResult' => ['indexStatusResult' => ['verdict' => 'PASS', 'coverageState' => 'Gönderildi ve dizine eklendi', 'googleCanonical' => $request['inspectionUrl'], 'lastCrawlTime' => '2026-10-20T10:00:00Z']]]),
        ]);
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'gsc_property' => 'sc-domain:example.com']);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        foreach (['/a', '/b'] as $path) {
            CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path), 'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html']);
        }
        CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/gone', 'url_hash' => sha1('https://example.com/gone'), 'state' => 'done', 'status_code' => 404]);

        $this->assertSame(2, \App\Google\UrlInspector::run($project));
        $this->assertSame(0, \App\Google\UrlInspector::run($project), 'checked pages wait a week');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'urlInspection') && $request['siteUrl'] === 'sc-domain:example.com');

        Livewire::test(PagesRelationManager::class, ['ownerRecord' => $crawl, 'pageClass' => ViewCrawl::class])
            ->assertSee('Gönderildi ve dizine eklendi')
            ->assertSee('Taranmış - şu anda dizine eklenmemiş')
            ->searchTable('/b')
            ->assertCanSeeTableRecords([CrawlPage::where('url', 'https://example.com/b')->first()]);

        $page = CrawlPage::where('url', 'https://example.com/a')->first();
        $html = view('crawls.page-detail', ['detail' => \App\Filament\Resources\Crawls\Support\PageDetail::for($page)])->render();
        $this->assertStringContainsString('20.10.2026', $html);
    }

    public function test_insights_list_what_to_work_on(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'gsc_property' => 'sc-domain:example.com']);
        $day = fn (int $ago) => now()->subDays($ago)->toDateString();
        $rows = [];
        foreach (range(3, 100) as $ago) {
            // /low: position 2, 1% CTR. /drop: 2 clicks a day before, none in the latest 28 days. /orphan: shown but not linked.
            $rows[] = ['day' => $day($ago), 'page' => 'https://example.com/low', 'clicks' => 0, 'impressions' => 10, 'position' => 2];
            $rows[] = ['day' => $day($ago), 'page' => 'https://example.com/drop', 'clicks' => $ago > 30 ? 2 : 0, 'impressions' => 5, 'position' => 15];
            $rows[] = ['day' => $day($ago), 'page' => 'https://example.com/orphan', 'clicks' => 1, 'impressions' => 3, 'position' => 9];
        }
        DB::table('gsc_page_days')->insert(array_map(fn ($r) => $r + ['project_id' => $project->id, 'page_hash' => sha1($r['page'])], $rows));
        DB::table('gsc_queries')->insert([
            ['project_id' => $project->id, 'page_hash' => sha1('https://example.com/low'), 'page' => 'https://example.com/low', 'query' => 'implant', 'clicks' => 1, 'impressions' => 400, 'position' => 12],
            ['project_id' => $project->id, 'page_hash' => sha1('https://example.com/drop'), 'page' => 'https://example.com/drop', 'query' => 'implant', 'clicks' => 0, 'impressions' => 80, 'position' => 15],
        ]);
        DB::table('gsc_inspections')->insert(['project_id' => $project->id, 'page_hash' => sha1('https://example.com/low'), 'url' => 'https://example.com/low', 'verdict' => 'PASS', 'coverage_state' => 'Dizinde', 'google_canonical' => 'https://example.com/other', 'user_canonical' => 'https://example.com/low', 'inspected_at' => now()]);

        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/', 'url_hash' => sha1('https://example.com/'), 'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'depth' => 0]);
        foreach (['/low', '/drop', '/dead'] as $path) {
            CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path), 'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'depth' => 1, 'inlinks' => 1]);
        }

        $insights = (new \App\Google\GscInsights($project))->all();
        $this->assertSame(['https://example.com/low'], $insights['low_ctr']->pluck('page')->all());
        $this->assertSame(['implant', 'implant'], $insights['opportunities']->pluck('query')->all());
        $this->assertSame(['implant'], $insights['cannibalization']->pluck('query')->all());
        $this->assertSame(['https://example.com/drop'], $insights['declining']->pluck('page')->all());
        $this->assertSame(['https://example.com/orphan'], $insights['orphans']->pluck('page')->all());
        $this->assertSame(['https://example.com/', 'https://example.com/dead'], $insights['zombies']->pluck('url')->all());
        $this->assertSame(['https://example.com/low'], $insights['index']->pluck('url')->all());

        $this->get(\App\Filament\Resources\Projects\ProjectResource::getUrl('insights', ['record' => $project]))
            ->assertOk()
            ->assertSee('Fırsat sorguları')
            ->assertSee('https://example.com/orphan');
    }
}
