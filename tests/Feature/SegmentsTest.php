<?php

namespace Tests\Feature;

use App\Crawler\HtmlParser;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Crawl;
use App\Models\CrawlIssue;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\User;
use App\Segments\Segments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SegmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_grouped_by_folder_and_depth(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'gsc_property' => 'sc-domain:example.com']);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $make = fn (string $path, int $depth, int $inlinks) => CrawlPage::create([
            'crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'depth' => $depth, 'inlinks' => $inlinks,
        ]);
        $home = $make('/', 0, 0);
        $a = $make('/blog/a', 1, 4);
        $b = $make('/blog/b', 2, 2);
        $make('/urun/x', 3, 1);
        CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $b->id, 'rule' => 'title_missing', 'severity' => 'high']);
        CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $b->id, 'rule' => 'h1_missing', 'severity' => 'medium']);
        CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $a->id, 'rule' => 'x_low', 'severity' => 'low']);
        DB::table('gsc_page_days')->insert(['project_id' => $project->id, 'day' => now()->subDays(2)->toDateString(), 'page' => $a->url, 'page_hash' => $a->url_hash, 'clicks' => 30, 'impressions' => 300, 'position' => 4]);

        $segments = (new Segments($project))->all();
        $blog = $segments['folders']->firstWhere('segment', '/blog/');
        $this->assertSame(2, $blog['pages']);
        $this->assertSame(50, $blog['health'], 'one of two blog pages has no important issue');
        $this->assertSame(1, $blog['high']);
        $this->assertSame(30, $blog['clicks']);
        $this->assertSame(3.0, $blog['avg_inlinks']);
        $this->assertSame(1, $segments['folders']->firstWhere('segment', Segments::ROOT)['pages']);
        $this->assertSame([0, 1, 2, 3], $segments['depths']->pluck('segment')->all());

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']))
            ->get(ProjectResource::getUrl('segments', ['record' => $project]))
            ->assertOk()->assertSee('/blog/')->assertSee('%50');
    }

    public function test_wordpress_types_categories_and_link_bands(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://example.com', 'gsc_property' => 'sc-domain:example.com']);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $make = fn (string $path, int $inlinks, ?string $type, ?string $category = null) => CrawlPage::create([
            'crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'depth' => 1, 'inlinks' => $inlinks,
            'wp_type' => $type, 'wp_category' => $category,
        ]);
        $a = $make('/a', 1, 'post', 'haberler');
        $b = $make('/b', 3, 'post', 'haberler');
        $make('/c', 60, 'page');
        $make('/kategori/haberler', 20, 'category', 'haberler');
        $make('/x', 4, null);
        foreach ([[$a, 10, 100, 18], [$b, 40, 400, 6]] as [$page, $clicks, $impressions, $position]) {
            DB::table('gsc_page_days')->insert(['project_id' => $project->id, 'day' => now()->subDays(2)->toDateString(), 'page' => $page->url, 'page_hash' => $page->url_hash, 'clicks' => $clicks, 'impressions' => $impressions, 'position' => $position]);
        }

        $segments = (new Segments($project))->all();
        $this->assertSame(['Yazı' => 2, 'Sayfa' => 1, 'Kategori arşivi' => 1], $segments['types']->pluck('pages', 'segment')->all());
        $this->assertSame(3, $segments['categories']->firstWhere('segment', 'haberler')['pages']);
        $this->assertSame(8.4, $segments['types']->firstWhere('segment', 'Yazı')['position'], 'impression-weighted');
        $this->assertSame(['0-1', '2-5', '11-25', '51+'], $segments['links']->pluck('segment')->all());
        $this->assertSame(2, $segments['links']->firstWhere('segment', '2-5')['pages']);
        $this->assertSame(6.0, $segments['links']->firstWhere('segment', '2-5')['position']);

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']))
            ->get(ProjectResource::getUrl('segments', ['record' => $project]))
            ->assertOk()->assertSee('WordPress kategorisi')->assertSee('haberler')->assertSee('İç link sayısı × pozisyon');
    }

    public function test_wordpress_type_and_category_from_theme_classes(): void
    {
        $wp = '<link rel="stylesheet" href="/wp-content/themes/x/style.css">';
        $parse = fn (string $body) => array_intersect_key(HtmlParser::parse('<html><head>'.$wp.'</head>'.$body.'</html>', 'https://x.com/p'), ['wp_type' => 1, 'wp_category' => 1]);

        $this->assertSame(['wp_type' => 'post', 'wp_category' => 'haberler'], $parse('<body class="post-template-default single single-post postid-7 single-format-standard"><article class="post-7 post type-post status-publish category-3 category-haberler tag-x"></article></body>'));
        $this->assertSame(['wp_type' => 'product', 'wp_category' => 'ayakkabi'], $parse('<body class="single single-product postid-9 woocommerce"><div class="product type-product status-publish product_cat-ayakkabi"></div></body>'));
        $this->assertSame(['wp_type' => 'page', 'wp_category' => null], $parse('<body class="page-template-default page page-id-5"></body>'));
        $this->assertSame(['wp_type' => 'category', 'wp_category' => 'haberler'], $parse('<body class="archive category category-haberler category-3"></body>'));
        $this->assertSame(['wp_type' => 'home', 'wp_category' => null], $parse('<body class="home page-template-default page page-id-2"></body>'));
        $this->assertSame(['wp_type' => 'archive:portfolio', 'wp_category' => null], $parse('<body class="archive post-type-archive post-type-archive-portfolio"></body>'));
        $this->assertNull(HtmlParser::parse('<html><body class="single single-post"></body></html>', 'https://x.com/p')['wp_type'], 'only on WordPress sites');
        $this->assertSame('portfolio arşivi', Segments::typeLabel('archive:portfolio'));
    }

    public function test_folder_name_from_url(): void
    {
        $this->assertSame('/blog/', Segments::folder('https://x.com/blog/yazi-1'));
        $this->assertSame(Segments::ROOT, Segments::folder('https://x.com/hakkimizda'));
        $this->assertSame(Segments::ROOT, Segments::folder('https://x.com/'));
    }
}
