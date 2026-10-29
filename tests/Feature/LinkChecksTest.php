<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Audit\SecurityHeaders;
use App\Crawler\HtmlParser;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_uncrawlable_links_and_weak_anchor_texts_are_counted(): void
    {
        $parsed = HtmlParser::parse('<html><body>'
            .'<a href="javascript:goTo(\'products\')">Ürünler</a>'
            .'<a href="javascript:void(0)">Menü</a>'
            .'<a onclick="window.location=\'/hakkimizda\'">Hakkımızda</a>'
            .'<a href="#" onclick="toggle()">Aç</a>'
            .'<a name="top"></a>'
            .'<a href="/implant">Implant tedavisi</a>'
            .'<a href="/blog/1">Devamını oku »</a>'
            .'<a href="/blog/2">devamını oku</a>'
            .'<a href="/x">Buraya tıklayın.</a>'
            .'<a href="https://facebook.com/klinik"><svg></svg></a>'
            .'<a href="https://instagram.com/klinik" aria-label="Instagram"><svg></svg></a>'
            .'<a href="/logo"><img src="/l.png" alt="Klinik ana sayfa"></a>'
            .'</body></html>', 'https://example.com/');

        $this->assertSame(2, $parsed['uncrawlable_links'], 'a javascript: call and an onclick navigation; void(0) and toggles are fine');
        $this->assertSame(1, $parsed['empty_anchors'], 'aria-label and image alt count as link text');
        $this->assertSame(3, $parsed['generic_anchor_count']);
        $this->assertSame(['Devamını oku »', 'devamını oku', 'Buraya tıklayın.'], $parsed['generic_anchors']);
    }

    public function test_security_headers(): void
    {
        $this->assertSame(
            ['Strict-Transport-Security', 'Content-Security-Policy', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy'],
            SecurityHeaders::problems(['Content-Type' => ['text/html']], true)
        );
        $this->assertSame(['Strict-Transport-Security (max-age 6 aydan kısa)'], SecurityHeaders::problems([
            'strict-transport-security' => ['max-age=300'],
            'Content-Security-Policy' => ["default-src 'self'; frame-ancestors 'self'"],
            'X-Content-Type-Options' => ['nosniff'],
            'Referrer-Policy' => ['strict-origin-when-cross-origin'],
        ], true), 'frame-ancestors replaces X-Frame-Options');
        $this->assertNotContains('Strict-Transport-Security', SecurityHeaders::problems([], false), 'HSTS only applies over HTTPS');
    }

    public function test_findings_are_reported(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $base = ['crawl_id' => $crawl->id, 'state' => 'done', 'status_code' => 200, 'content_type' => 'text/html'];
        CrawlPage::create($base + ['url' => 'https://example.com/', 'url_hash' => sha1('/'), 'depth' => 0, 'security_headers' => 'Content-Security-Policy,Referrer-Policy']);
        CrawlPage::create($base + ['url' => 'https://example.com/a', 'url_hash' => sha1('/a'), 'depth' => 1, 'security_headers' => 'Referrer-Policy',
            'uncrawlable_links' => 2, 'empty_anchors' => 1, 'generic_anchors' => 3, 'generic_anchor_texts' => 'devamı | buraya tıklayın']);

        (new Auditor)->run($crawl);

        $detail = fn (string $rule) => $crawl->issues()->where('rule', $rule)->value('detail');
        $this->assertSame('Content-Security-Policy, Referrer-Policy', $detail('security_headers_missing'));
        $this->assertSame(1, $crawl->issues()->where('rule', 'security_headers_missing')->count(), 'site-wide, so reported once on the home page');
        $this->assertSame('2 link', $detail('uncrawlable_links'));
        $this->assertSame('1 link', $detail('anchor_text_empty'));
        $this->assertSame('3 link: devamı | buraya tıklayın', $detail('anchor_text_generic'));
    }
}
