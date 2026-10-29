<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Crawler\HtmlParser;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NapConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_organisation_name_and_phone_are_read(): void
    {
        $html = fn (string $phone, string $visible) => '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebSite","name":"Site"},{"@type":"Dentist","name":"Gülüş Diş","telephone":"'.$phone.'"}]}</script></head>'
            .'<body><p>Bizi arayın: '.$visible.'</p></body></html>';

        $parsed = HtmlParser::parse($html('+90 212 555 00 00', '0 (212) 555 00 00'), 'https://example.com/');
        $this->assertSame(['name' => 'Gülüş Diş', 'phone' => '2125550000', 'logo' => false], $parsed['entity']);
        $this->assertSame([], $parsed['schema_mismatch']);

        $parsed = HtmlParser::parse($html('+90 212 555 00 00', '0 (216) 444 11 11'), 'https://example.com/');
        $this->assertSame(['Şemadaki telefon (+90 212 555 00 00) sayfada görünmüyor'], $parsed['schema_mismatch']);
    }

    public function test_differing_values_across_pages_are_reported(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = fn (string $path, int $depth, ?array $entity) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'depth' => $depth, 'canonical' => 'https://example.com'.$path, 'entity' => $entity]);

        $home = $page('/', 0, ['name' => 'Gülüş Diş', 'phone' => '2125550000']);
        $page('/a/', 1, ['name' => 'gülüş  diş', 'phone' => '2125550000']);
        $page('/b/', 1, ['name' => 'Gülüş Diş Kliniği', 'phone' => '2164441111']);

        (new Auditor)->run($crawl);

        $this->assertSame('Ad: "Gülüş Diş" (2 sayfa), "Gülüş Diş Kliniği" (1 sayfa) · Telefon: …2125550000 (2 sayfa), …2164441111 (1 sayfa)',
            $home->issues()->where('rule', 'nap_inconsistent')->value('detail'));
    }
}
