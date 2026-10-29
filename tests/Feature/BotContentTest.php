<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Crawler\BotAccess;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_bots_shown_a_challenge_page_are_reported(): void
    {
        $full = '<html><body><p>'.str_repeat('implant diş tedavisi ', 100).'</p></body></html>';
        Http::fake(fn (Request $request) => str_contains($request->header('User-Agent')[0], 'GPTBot')
            ? Http::response('<html><body><p>Just a moment, checking your browser</p></body></html>', 200, ['Content-Type' => 'text/html'])
            : Http::response($full, 200, ['Content-Type' => 'text/html; charset=utf-8']));

        $rows = BotAccess::fromRobots('', 'https://example.com/');
        $rows = BotAccess::fetch($rows, ['gptbot', 'oai-searchbot'], 'https://example.com/', 5);
        $this->assertSame(300, $rows['oai-searchbot']['words']);
        $this->assertSame(6, $rows['gptbot']['words']);

        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => [], 'bot_access' => $rows]);
        $home = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/', 'url_hash' => sha1('https://example.com/'), 'depth' => 0,
            'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html', 'word_count' => 300]);

        (new Auditor)->run($crawl);

        $detail = $home->issues()->where('rule', 'bot_content_differs')->value('detail');
        $this->assertSame('Normal ziyaretçi 300 kelime görüyor; GPTBot (OpenAI eğitim) (6 kelime)', $detail);
    }
}
