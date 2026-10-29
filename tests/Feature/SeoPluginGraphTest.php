<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Crawler\HtmlParser;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoPluginGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_incomplete_plugin_graph_is_reported(): void
    {
        $this->assertFalse(HtmlParser::parse('<html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"Klinik"}</script></head></html>', 'https://example.com/')['entity']['logo']);

        $run = function (string $types, array $entity) {
            $project = Project::factory()->create(['platform' => 'wordpress']);
            $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
            $home = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/', 'url_hash' => sha1('https://example.com/'), 'depth' => 0,
                'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://example.com/', 'seo_plugin' => 'yoast',
                'schema_types' => $types, 'entity' => $entity]);
            (new Auditor)->run($crawl);

            return $home->issues()->where('rule', 'wp_seo_schema_incomplete')->value('detail');
        };

        $this->assertSame('Yoast SEO: WebSite düğümü yok, kurum logosu yok', $run('Organization,WebPage', ['name' => 'Klinik', 'phone' => null, 'logo' => false]));
        $this->assertNull($run('Organization,WebSite,WebPage', ['name' => 'Klinik', 'phone' => null, 'logo' => true]));
    }
}
