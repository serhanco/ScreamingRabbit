<?php

namespace Tests\Feature;

use App\Crawler\SiteComparison;
use App\Filament\Pages\SiteCompare;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SiteComparisonTest extends TestCase
{
    use RefreshDatabase;

    private function crawl(string $host, array $pages): Crawl
    {
        $project = Project::factory()->create(['base_url' => "https://{$host}"]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'finished_at' => now()]);
        foreach ($pages as $path => $attrs) {
            $url = "https://{$host}{$path}";
            CrawlPage::create(['crawl_id' => $crawl->id, 'url' => $url, 'url_hash' => sha1($url), 'state' => 'done', 'status_code' => 200,
                'content_type' => 'text/html', 'canonical' => $url, 'title' => 'T', ...$attrs]);
        }

        return $crawl;
    }

    public function test_sites_are_matched_by_path(): void
    {
        $live = $this->crawl('site.com', ['/' => [], '/hizmetler/' => [], '/eski/' => [], '/blog/' => ['title' => 'Blog']]);
        $staging = $this->crawl('test.site.com', ['/' => ['meta_robots' => 'noindex'], '/hizmetler/' => [], '/yeni/' => [], '/blog/' => ['title' => 'Blog | Yeni']]);

        $data = (new SiteComparison($live, $staging))->data();
        $this->assertSame(3, $data['common']);
        $this->assertSame(['/eski/'], $data['only_live']['sample']);
        $this->assertSame(['/yeni/'], $data['only_staging']['sample']);
        $this->assertSame([['path' => '/', 'live' => 'Evet', 'staging' => 'Hayır']], $data['fields']['indexable']['sample']);
        $this->assertSame([['path' => '/blog/', 'live' => 'Blog', 'staging' => 'Blog | Yeni']], $data['fields']['title']['sample']);
        $this->assertArrayNotHasKey('canonical', $data['fields'], 'canonicals are compared by path');

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
        Livewire::test(SiteCompare::class)
            ->set('data.live', $live->id)->set('data.staging', $staging->id)
            ->call('compare')
            ->assertHasNoErrors()
            ->assertSee('olmayan canlı sayfalar')
            ->assertSee('/eski/');
    }
}
