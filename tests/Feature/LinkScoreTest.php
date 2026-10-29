<?php

namespace Tests\Feature;

use App\Crawler\LinkScore;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LinkScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_linked_from_everywhere_score_highest(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $pages = [];
        foreach (['/', '/hizmetler', '/blog/1', '/blog/2', '/blog/3', '/eski', '/gizli'] as $path) {
            $pages[$path] = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path), 'state' => 'done',
                'status_code' => $path === '/eski' ? 301 : 200, 'content_type' => $path === '/eski' ? null : 'text/html', 'redirect_to' => $path === '/eski' ? 'https://example.com/hizmetler' : null]);
        }
        $link = fn (string $from, string $to, bool $nofollow = false) => DB::table('crawl_links')->insert(['crawl_id' => $crawl->id, 'from_page_id' => $pages[$from]->id, 'to_hash' => sha1('https://example.com'.$to), 'nofollow' => $nofollow]);

        foreach (['/', '/blog/1', '/blog/2', '/blog/3'] as $from) {
            $link($from, '/hizmetler');
            $link($from, '/');
        }
        $link('/', '/blog/1');
        $link('/blog/1', '/blog/2');
        $link('/blog/2', '/blog/3');
        $link('/blog/3', '/eski');
        $link('/blog/3', '/gizli', nofollow: true);

        LinkScore::compute($crawl);

        $score = fn (string $path) => $pages[$path]->fresh()->link_score;
        $this->assertSame(100, max($score('/'), $score('/hizmetler')));
        $this->assertSame(1, $score('/gizli'), 'reached only through a nofollow link');
        $this->assertGreaterThan($score('/blog/3'), $score('/blog/1'));
        $this->assertGreaterThan($score('/'), $score('/hizmetler'), 'the redirect passes its score on');
        $this->assertNull($score('/eski'), 'redirects themselves get no score');
    }
}
