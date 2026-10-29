<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ProjectWordPress;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Models\WpConnection;
use App\Models\WpEvent;
use App\WordPress\Connector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use SR_Signature;
use Tests\TestCase;

class WordPressConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function codeOf(string $token): string
    {
        $this->assertStringStartsWith('SR1.', $token);

        return json_decode(base64_decode(strtr(substr($token, 4), '-_', '+/')), true)['c'];
    }

    public function test_pairing_gives_the_site_a_secret_once(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://www.klinik.com']);
        $code = $this->codeOf(Connector::newCode($project));

        $this->postJson('/wp-baglanti/eslestir', ['code' => $code, 'site_url' => 'https://baska.com/'])
            ->assertStatus(422)->assertJsonPath('message', 'Bu kod www.klinik.com sitesi için. Kodu o sitenin WordPress paneline yapıştırın.');

        $secret = $this->postJson('/wp-baglanti/eslestir', ['code' => $code, 'site_url' => 'https://klinik.com/', 'plugin_version' => '0.1.0'])
            ->assertOk()->json('secret');
        $connection = $project->wpConnection()->first();
        $this->assertSame($secret, $connection->secret);
        $this->assertTrue($connection->isConnected());
        $this->assertNull($connection->code_hash);

        $this->postJson('/wp-baglanti/eslestir', ['code' => $code, 'site_url' => 'https://klinik.com/'])->assertStatus(422);
    }

    public function test_expired_codes_do_not_pair(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://klinik.com']);
        $code = $this->codeOf(Connector::newCode($project));
        $this->travel(Connector::CODE_MINUTES + 1)->minutes();

        $this->assertArrayHasKey('error', Connector::pair($code, 'https://klinik.com/', null));
    }

    public function test_app_and_plugin_sign_the_same_way(): void
    {
        require_once base_path('wordpress-plugin/screamingrabbit-connector/includes/class-sr-signature.php');
        $now = time();
        $signature = Connector::sign('gizli', 'GET', '/screamingrabbit/v1/inventory', (string) $now, 'abcdefghijklmnop1234', '');

        $this->assertSame(SR_Signature::sign('gizli', 'get', '/screamingrabbit/v1/inventory', $now, 'abcdefghijklmnop1234', ''), $signature);
        $this->assertNull(SR_Signature::problem('gizli', 'GET', '/screamingrabbit/v1/inventory', (string) $now, 'abcdefghijklmnop1234', '', $signature, $now + 60));
        $this->assertSame('bad_signature', SR_Signature::problem('başka', 'GET', '/screamingrabbit/v1/inventory', (string) $now, 'abcdefghijklmnop1234', '', $signature, $now));
        $this->assertSame('stale', SR_Signature::problem('gizli', 'GET', '/screamingrabbit/v1/inventory', (string) $now, 'abcdefghijklmnop1234', '', $signature, $now + 600));
    }

    public function test_sync_stores_the_inventory_or_the_reason_it_failed(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://klinik.com']);
        $connection = WpConnection::create(['project_id' => $project->id, 'secret' => 'gizli', 'site_url' => 'https://klinik.com/', 'connected_at' => now()]);
        Http::fake(['www.wpvulnerability.net/*' => Http::response(['data' => ['vulnerability' => null]]), 'klinik.com/*' => Http::sequence()
            ->push(['plugin_version' => '0.1.0', 'core' => ['version' => '6.8.1', 'update' => null], 'plugins' => []])
            ->push(['code' => 'sr_unauthorized', 'message' => 'bad_signature'], 401)]);

        $this->assertSame(1, Connector::runDue());
        $this->assertSame('6.8.1', $connection->fresh()->inventory['core']['version']);
        Http::assertSent(function (Request $request) {
            $route = '/screamingrabbit/v1/inventory';

            return ($request->data()['rest_route'] ?? null) === $route && hash_equals(
                Connector::sign('gizli', 'GET', $route, $request->header('X-SR-Timestamp')[0], $request->header('X-SR-Nonce')[0], ''),
                $request->header('X-SR-Signature')[0],
            );
        });

        $this->assertSame(0, Connector::runDue(), 'synced within the day');
        $this->assertFalse(Connector::sync($connection->fresh()));
        $this->assertStringContainsString('Eklenti isteği reddetti (bad_signature)', $connection->fresh()->sync_error);
        $this->assertSame('6.8.1', $connection->fresh()->inventory['core']['version'], 'the last good inventory is kept');
    }

    public function test_page_shows_the_inventory_and_makes_codes(): void
    {
        $admin = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $project = Project::factory()->create(['base_url' => 'https://klinik.com', 'platform' => 'wordpress']);
        WpConnection::create(['project_id' => $project->id, 'secret' => 'gizli', 'site_url' => 'https://klinik.com/', 'plugin_version' => '0.2.0', 'connected_at' => now(), 'synced_at' => now(),
            'inventory' => ['core' => ['version' => '6.8.1', 'update' => '6.9'], 'environment' => ['php' => '8.2.1'], 'settings' => ['blog_public' => true, 'admins' => 2],
                'plugins' => [['name' => 'Yoast SEO', 'version' => '25.0', 'active' => true, 'update' => '25.1', 'auto_update' => false]], 'themes' => [], 'content' => ['post' => ['publish' => 12, 'draft' => 3, 'future' => 0, 'private' => 0]],
                'seo' => ['plugin' => 'Yoast SEO', 'noindex_types' => [], 'sitemap' => true], 'site_health' => [['test' => 'timezone', 'status' => 'recommended', 'label' => 'Saat dilimi ayarlı değil']],
                'integrity' => ['version' => '6.8.1', 'modified' => ['wp-login.php'], 'modified_count' => 1, 'missing' => [], 'missing_count' => 0]],
            'vulnerabilities' => [['type' => 'plugin', 'slug' => 'wordpress-seo', 'name' => 'Yoast SEO', 'version' => '25.0', 'items' => [['title' => 'Stored XSS', 'severity' => 'Orta', 'score' => 6.4, 'fixed_in' => '25.1', 'unfixed' => false, 'link' => null]]]],
            'vulnerabilities_checked_at' => now()]);

        WpEvent::create(['project_id' => $project->id, 'event' => 'published', 'url' => 'https://klinik.com/yeni/', 'received_at' => now(), 'checked_at' => now(), 'problems' => ['Sayfa noindex: Google dizine almaz']]);

        $this->actingAs($admin)->get(ProjectResource::getUrl('wordpress', ['record' => $project]))
            ->assertOk()->assertSee('6.9 güncellemesi var')->assertSee('Yoast SEO')->assertSee('25.1 var')
            ->assertSee('site haritası açık')->assertSee('Saat dilimi ayarlı değil')->assertSee('wp-login.php')
            ->assertSee('Bu seferlik ZIP')->assertSee('Sayfa noindex: Google dizine almaz')->assertSee('Stored XSS')->assertSee('25.1 sürümünde düzeldi');

        Livewire::actingAs($admin)->test(ProjectWordPress::class, ['record' => $project->id])
            ->callAction('code')->assertSet('code', fn (string $code) => str_starts_with($code, 'SR1.'));
        $this->assertNotNull($project->wpConnection()->first()->code_hash);
    }

    public function test_plugin_zip_downloads_for_members(): void
    {
        $this->get('/wp-baglanti/eklenti.zip')->assertForbidden();

        $response = $this->actingAs(User::factory()->create())->get('/wp-baglanti/eklenti.zip')->assertOk();
        $zip = new \ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertNotFalse($zip->locateName('screamingrabbit-connector/screamingrabbit-connector.php'));
        $this->assertNotFalse($zip->locateName('screamingrabbit-connector/includes/class-sr-api.php'));
    }

    public function test_paired_sites_can_download_plugin_updates(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://klinik.com']);
        WpConnection::create(['project_id' => $project->id, 'secret' => 'gizli', 'site_url' => 'https://www.klinik.com/', 'connected_at' => now()]);
        $version = Connector::pluginVersion();
        $token = fn (string $host, string $secret = 'gizli', ?string $v = null) => hash_hmac('sha256', "package\n".($v ?? $version)."\n".$host, $secret);

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        $this->getJson(route('wordpress.version'))->assertOk()->assertJson(['version' => $version]);

        $this->get(route('wordpress.package', ['v' => $version, 's' => 'www.klinik.com', 't' => $token('www.klinik.com')]))
            ->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->get(route('wordpress.package', ['v' => $version, 's' => 'www.klinik.com', 't' => $token('www.klinik.com', 'baska')]))->assertForbidden();
        $this->get(route('wordpress.package', ['v' => '0.0.1', 's' => 'www.klinik.com', 't' => $token('www.klinik.com', 'gizli', '0.0.1')]))->assertForbidden();
        $this->get(route('wordpress.package', ['v' => $version, 's' => 'diger.com', 't' => $token('diger.com')]))->assertForbidden();
        $this->get(route('wordpress.plugin'))->assertForbidden();
    }
}
