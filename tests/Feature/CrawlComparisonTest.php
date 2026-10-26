<?php

namespace Tests\Feature;

use App\Crawler\CrawlComparison;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Crawl;
use App\Models\CrawlIssue;
use App\Models\CrawlPage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrawlComparisonTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, array<string, mixed>>  $pages  path => page attributes
     * @param  array<int, array{0: string, 1: string, 2: string}>  $issues  [rule, severity, path]
     */
    private function crawl(Project $project, array $pages, array $issues, int $score): Crawl
    {
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'health_score' => $score]);
        $ids = [];
        foreach ($pages as $path => $attrs) {
            $ids[$path] = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1('https://example.com'.$path), 'state' => 'done', 'status_code' => 200, ...$attrs])->id;
        }
        foreach ($issues as [$rule, $severity, $path]) {
            CrawlIssue::create(['crawl_id' => $crawl->id, 'page_id' => $ids[$path], 'rule' => $rule, 'severity' => $severity]);
        }

        return $crawl;
    }

    public function test_compares_with_the_previous_crawl(): void
    {
        $project = Project::factory()->create();
        $first = $this->crawl($project, [
            '/' => ['title' => 'Ana sayfa'],
            '/a' => ['title' => 'Eski başlık'],
            '/gone' => ['title' => 'Gidecek'],
            '/b' => ['title' => 'B'],
        ], [['title_missing', 'high', '/a'], ['h1_missing', 'medium', '/b']], 60);
        $second = $this->crawl($project, [
            '/' => ['title' => 'Ana sayfa'],
            '/a' => ['title' => 'Yeni başlık'],
            '/b' => ['title' => 'B', 'status_code' => 404],
            '/new' => ['title' => 'Yeni'],
        ], [['h1_missing', 'medium', '/b'], ['http_4xx', 'high', '/b'], ['x_new', 'low', '/new']], 70);

        $data = (new CrawlComparison($second))->data();

        $this->assertSame([60, 70], $data['score']);
        $this->assertSame(1, $data['new_pages']['count']);
        $this->assertSame('https://example.com/new', $data['new_pages']['sample'][0]->url);
        $this->assertSame('https://example.com/gone', $data['lost_pages']['sample'][0]->url);
        $this->assertSame(1, $data['changed']['title']['count']);
        $this->assertSame('Eski başlık', $data['changed']['title']['sample'][0]->before_value);
        $this->assertSame('Yeni başlık', $data['changed']['title']['sample'][0]->after_value);
        $this->assertSame(1, $data['changed']['status_code']['count']);
        $this->assertArrayNotHasKey('canonical', $data['changed']);
        // http_4xx is new on a page both crawls saw; x_new sits on a page the first crawl never reached, so it is not "new".
        $this->assertSame(['http_4xx'], $data['new_issues']->pluck('rule')->all());
        $this->assertSame(['title_missing'], $data['fixed_issues']->pluck('rule')->all(), 'h1_missing stays, so it is neither new nor fixed');

        $this->assertNull((new CrawlComparison($first))->data(), 'the first crawl has nothing to compare with');

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']))
            ->get(CrawlResource::getUrl('view', ['record' => $second]))
            ->assertOk()->assertSee('Önceki taramaya göre değişenler')->assertSee('Yeni başlık');
    }
}
