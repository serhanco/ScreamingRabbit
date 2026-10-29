<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Crawler\StagingHost;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StagingLeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_hosts_are_recognised(): void
    {
        $this->assertTrue(StagingHost::is('staging.ornek.com.tr', 'www.ornek.com.tr'));
        $this->assertTrue(StagingHost::is('dev2.example.com', 'example.com'));
        $this->assertTrue(StagingHost::is('example.wpengine.com', 'example.com'));
        $this->assertFalse(StagingHost::is('blog.example.com', 'example.com'));
        $this->assertFalse(StagingHost::is('staging.other.com', 'example.com'));
        $this->assertFalse(StagingHost::is('www.example.com', 'example.com'));
        $this->assertFalse(StagingHost::is('test.com.tr', 'ornek.com.tr'));
    }

    public function test_links_and_canonicals_to_staging_are_reported(): void
    {
        $project = Project::factory()->create(['base_url' => 'https://www.example.com']);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = fn (string $path, array $attrs = []) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://www.example.com'.$path, 'url_hash' => sha1('https://www.example.com'.$path),
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://www.example.com'.$path, ...$attrs]);

        $home = $page('/');
        $copy = $page('/kopya/', ['depth' => 1, 'canonical' => 'https://staging.example.com/kopya/']);
        foreach (['https://staging.example.com/a/', 'https://staging.example.com/b/', 'https://blog.example.com/'] as $url) {
            $id = DB::table('crawl_external_links')->insertGetId(['crawl_id' => $crawl->id, 'url' => $url, 'url_hash' => sha1($url), 'state' => 'done', 'status_code' => 200]);
            DB::table('crawl_external_refs')->insert(['crawl_id' => $crawl->id, 'link_id' => $id, 'page_id' => $home->id]);
        }

        (new Auditor)->run($crawl);

        $this->assertSame('2 link: https://staging.example.com/a/', $home->issues()->where('rule', 'staging_link')->value('detail'));
        $this->assertSame('canonical: https://staging.example.com/kopya/', $copy->issues()->where('rule', 'staging_link')->value('detail'));
    }
}
