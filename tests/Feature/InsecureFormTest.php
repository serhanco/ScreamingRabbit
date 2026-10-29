<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Crawler\HtmlParser;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsecureFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_forms_sent_without_encryption_are_counted(): void
    {
        $forms = '<form action="http://example.com/gonder"><input name="ad"></form>'
            .'<form action="/ara"><input name="q"></form>'
            .'<form action="https://example.com/giris"><input type="password"></form>';

        $this->assertSame(1, HtmlParser::parse("<html><body>{$forms}</body></html>", 'https://example.com/iletisim')['insecure_forms']);
        // On an http page only the login form matters: a search box on an http site is not a credential leak.
        $this->assertSame(1, HtmlParser::parse('<html><body><form><input type="PASSWORD"></form><form action="/ara"></form></body></html>', 'http://example.com/giris')['insecure_forms']);
    }

    public function test_seopress_and_the_seo_framework_are_detected(): void
    {
        $parsed = HtmlParser::parse('<html><head><!-- This site is optimized with The SEO Framework --><link rel="stylesheet" href="/wp-content/plugins/wp-seopress/a.css"></head><body></body></html>', 'https://example.com/');

        $this->assertSame('seopress,tsf', $parsed['seo_plugin']);
    }

    public function test_insecure_form_becomes_a_finding(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com/', 'url_hash' => sha1('https://example.com/'), 'state' => 'done',
            'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://example.com/', 'insecure_forms' => 2]);

        (new Auditor)->run($crawl);

        $this->assertSame('2 form', $page->issues()->where('rule', 'insecure_form')->value('detail'));
    }
}
