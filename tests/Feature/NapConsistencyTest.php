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
        $html = fn (string $phone, string $visible) => '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebSite","name":"Site"},{"@type":"Dentist","name":"Gülüş Diş","telephone":"'.$phone.'","address":{"@type":"PostalAddress","streetAddress":"Bağdat Cad. No: 5","postalCode":"34710"}}]}</script></head>'
            .'<body><p>Bizi arayın: '.$visible.'</p></body></html>';

        $parsed = HtmlParser::parse($html('+90 212 555 00 00', '0 (212) 555 00 00'), 'https://example.com/');
        $this->assertSame(['name' => 'Gülüş Diş', 'phone' => '2125550000', 'logo' => false, 'address' => 'bağdat cad no: 5 34710'], $parsed['entity']);
        $this->assertSame([], $parsed['schema_mismatch']);

        $parsed = HtmlParser::parse($html('+90 212 555 00 00', '0 (216) 444 11 11'), 'https://example.com/');
        $this->assertSame(['Şemadaki telefon (+90 212 555 00 00) sayfada görünmüyor'], $parsed['schema_mismatch']);
    }

    public function test_duplicate_main_entities_with_different_names_are_listed(): void
    {
        $ld = fn (array $data) => '<script type="application/ld+json">'.json_encode(['@context' => 'https://schema.org'] + $data).'</script>';
        $html = '<html><head>'.$ld(['@graph' => [['@type' => 'Organization', 'name' => 'Gülüş Diş'], ['@type' => 'WebSite', 'name' => 'Gülüş']]])
            .$ld(['@type' => 'Organization', 'name' => 'Gülüş Diş Kliniği']).$ld(['@type' => 'WebSite', 'name' => 'gülüş']).'</head><body></body></html>';

        $this->assertSame(['İki Organization şeması farklı ad veriyor: "Gülüş Diş" / "Gülüş Diş Kliniği"'], HtmlParser::parse($html, 'https://example.com/')['schema_mismatch']);
    }

    public function test_differing_values_across_pages_are_reported(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = fn (string $path, int $depth, ?array $entity) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'depth' => $depth, 'canonical' => 'https://example.com'.$path, 'entity' => $entity]);

        $home = $page('/', 0, ['name' => 'Gülüş Diş', 'phone' => '2125550000', 'address' => 'bağdat cad 5']);
        $page('/a/', 1, ['name' => 'gülüş  diş', 'phone' => '2125550000', 'address' => 'bağdat cad 5']);
        $page('/b/', 1, ['name' => 'Gülüş Diş Kliniği', 'phone' => '2164441111', 'address' => 'moda cad 9']);

        (new Auditor)->run($crawl);

        $this->assertSame('Ad: "Gülüş Diş" (2 sayfa), "Gülüş Diş Kliniği" (1 sayfa) · Telefon: …2125550000 (2 sayfa), …2164441111 (1 sayfa) · Adres: "bağdat cad 5" (2 sayfa), "moda cad 9" (1 sayfa)',
            $home->issues()->where('rule', 'nap_inconsistent')->value('detail'));
    }
}
