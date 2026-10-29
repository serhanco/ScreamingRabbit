<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\WpConnection;
use App\WordPress\Vulnerabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WordPressVulnerabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $name, array $operator, string $severity = 'h', float $score = 7.5): array
    {
        return ['name' => $name, 'operator' => $operator + ['min_version' => null, 'min_operator' => null], 'impact' => ['cvss' => ['score' => $score, 'severity' => $severity]],
            'source' => [['name' => 'CVE-2024-1', 'link' => 'https://www.cve.org/CVERecord?id=CVE-2024-1']]];
    }

    private function connection(): WpConnection
    {
        $project = Project::factory()->create(['platform' => 'wordpress']);

        return WpConnection::create(['project_id' => $project->id, 'secret' => 'x', 'connected_at' => now(), 'synced_at' => now(), 'inventory' => [
            'core' => ['version' => '6.8.1'],
            'plugins' => [
                ['file' => 'contact-form-7/wp-contact-form-7.php', 'name' => 'Contact Form 7', 'version' => '5.3.1', 'active' => true],
                ['file' => 'hello.php', 'name' => 'Hello Dolly', 'version' => '1.7', 'active' => false],
            ],
            'themes' => [['slug' => 'astra', 'name' => 'Astra', 'version' => '4.1']],
        ]]);
    }

    public function test_installed_versions_are_matched_against_the_database(): void
    {
        Http::fake([
            'www.wpvulnerability.net/plugin/contact-form-7/' => Http::response(['data' => ['vulnerability' => [
                $this->entry('Unrestricted File Upload', ['max_version' => '5.3.2', 'max_operator' => 'lt'], 'c', 10.0),
                $this->entry('Old XSS', ['max_version' => '5.0', 'max_operator' => 'le']),
                $this->entry('Reflected XSS', ['min_version' => '5.0', 'min_operator' => 'ge', 'max_version' => '5.8', 'max_operator' => 'lt'], 'm', 6.1),
            ]]]),
            'www.wpvulnerability.net/theme/astra/' => Http::response(['data' => ['vulnerability' => [$this->entry('Fixed long ago', ['max_version' => '3.0', 'max_operator' => 'lt'])]]]),
            'www.wpvulnerability.net/core/6.8.1/' => Http::response(['data' => ['vulnerability' => null]]),
        ]);
        $connection = $this->connection();

        $this->assertTrue(Vulnerabilities::check($connection));
        $found = $connection->fresh()->vulnerabilities;

        $this->assertCount(1, $found, 'only Contact Form 7 is affected; single-file plugins are not looked up');
        $this->assertSame(['Unrestricted File Upload', 'Reflected XSS'], array_column($found[0]['items'], 'title'));
        $this->assertSame(['Kritik', '5.3.2', 'https://www.cve.org/CVERecord?id=CVE-2024-1'], [$found[0]['items'][0]['severity'], $found[0]['items'][0]['fixed_in'], $found[0]['items'][0]['link']]);
        Http::assertSentCount(3);

        Vulnerabilities::check($connection);
        Http::assertSentCount(3);

        $crawl = Crawl::create(['project_id' => $connection->project_id, 'status' => 'completed', 'settings' => [], 'detected_platform' => 'wordpress']);
        $home = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/', 'url_hash' => sha1('https://example.com/'), 'state' => 'done',
            'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://example.com/', 'depth' => 0]);
        (new Auditor)->run($crawl);

        $this->assertSame('Contact Form 7 5.3.1: Unrestricted File Upload +1 açık (5.8 ile düzeldi)', $home->issues()->where('rule', 'wp_vulnerable_component')->value('detail'));
    }

    public function test_a_failed_lookup_keeps_the_previous_result(): void
    {
        Http::fake(['www.wpvulnerability.net/*' => Http::response('', 503)]);
        $connection = $this->connection();
        $connection->update(['vulnerabilities' => [['name' => 'Eski', 'items' => []]]]);

        $this->assertFalse(Vulnerabilities::check($connection));
        $this->assertSame('Eski', $connection->fresh()->vulnerabilities[0]['name']);
        Http::assertSentCount(1);
    }
}
