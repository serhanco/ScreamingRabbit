<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Audit\SchemaInventory;
use App\Audit\SchemaRules;
use App\Crawler\HtmlParser;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaAuditTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $head, string $body = ''): array
    {
        return HtmlParser::parse("<html><head>{$head}</head><body>{$body}</body></html>", 'https://example.com/a');
    }

    private function ld(array $data): string
    {
        return '<script type="application/ld+json">'.json_encode($data).'</script>';
    }

    public function test_rich_result_properties_are_checked(): void
    {
        $parsed = $this->page($this->ld(['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'Dentist', '@id' => '#org', 'name' => 'Klinik', 'telephone' => '1', 'url' => 'x', 'image' => 'x', 'openingHours' => 'Mo', 'geo' => [], 'priceRange' => '$$'],
            ['@type' => 'BlogPosting', 'headline' => 'Implant', 'publisher' => ['@type' => 'Organization', 'name' => 'Klinik'], 'author' => ['@id' => '#org', '@type' => 'Person']],
            ['@type' => 'Product', 'name' => 'Diş beyazlatma', 'offers' => ['@type' => 'Offer', 'priceCurrency' => 'TRY']],
        ]]));

        $problems = collect($parsed['schema_problems'])->keyBy('type');
        $this->assertSame(['address'], $problems['Dentist']['required'], 'LocalBusiness subtype needs an address');
        $this->assertSame([], $problems['BlogPosting']['required']);
        $this->assertSame(['image', 'datePublished', 'dateModified'], $problems['BlogPosting']['recommended']);
        $this->assertSame(['price|priceSpecification'], $problems['Offer']['required']);
        $this->assertSame([], $problems['Offer']['recommended'], 'nested nodes are only held to required properties');
        $this->assertFalse($problems->has('Organization'), 'a nested publisher with just a name is fine');
        $this->assertFalse($problems->has('Person'), 'a bare @id reference is not checked');
        $this->assertSame(['Product'], array_keys($problems->only('Product')->all()));
        $this->assertSame([], $problems['Product']['required'], 'offers is there');
        $this->assertContains('Dentist', $parsed['schema_types']);
    }

    public function test_duplicate_main_types_and_microdata_are_found(): void
    {
        $parsed = $this->page(
            $this->ld(['@type' => 'Organization', 'name' => 'A', 'url' => 'x', 'logo' => 'x', 'sameAs' => ['x']]).$this->ld(['@type' => 'MedicalOrganization', 'name' => 'A']),
            '<div itemscope itemtype="https://schema.org/BreadcrumbList"></div>'
        );

        $this->assertSame(['Organization'], $parsed['schema_duplicates']);
        $this->assertContains('BreadcrumbList', $parsed['schema_types']);
        $this->assertNull(SchemaRules::check(['@type' => 'WebSite', 'name' => 'x']), 'no rich result rules for WebSite');
    }

    public function test_inventory_and_findings_on_the_crawl_page(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        foreach (['/a' => [['type' => 'Product', 'required' => ['offers|review|aggregateRating'], 'recommended' => []]], '/b' => null] as $path => $problems) {
            CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1($path), 'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html',
                'schema_types' => 'Product,Offer', 'schema_problems' => $problems]);
        }

        $product = collect(SchemaInventory::for($crawl))->firstWhere('type', 'Product');
        $this->assertSame(['type' => 'Product', 'pages' => 2, 'required' => 1, 'recommended' => 0, 'duplicates' => 0], $product);

        (new Auditor)->run($crawl);
        $this->assertSame(1, $crawl->issues()->where('rule', 'schema_missing_required')->count());
        $this->assertStringContainsString('offers veya review veya aggregateRating', $crawl->issues()->where('rule', 'schema_missing_required')->value('detail'));

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']))
            ->get(CrawlResource::getUrl('view', ['record' => $crawl]))->assertOk()->assertSee('Yapısal veri envanteri');
    }
}
