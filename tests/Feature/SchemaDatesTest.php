<?php

namespace Tests\Feature;

use App\Crawler\HtmlParser;
use Tests\TestCase;

class SchemaDatesTest extends TestCase
{
    private function parse(array $article, string $head = '', string $body = ''): array
    {
        $ld = '<script type="application/ld+json">'.json_encode(['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => 'Diş beyazlatma'] + $article).'</script>';

        return HtmlParser::parse("<html><head><title>Diş beyazlatma</title>{$head}{$ld}</head><body><h1>Diş beyazlatma</h1>{$body}</body></html>", 'https://example.com/a');
    }

    public function test_matching_dates_pass(): void
    {
        $parsed = $this->parse(
            ['datePublished' => '2026-03-10T09:00:00+03:00', 'dateModified' => '2026-05-01T10:00:00+03:00'],
            '<meta property="article:published_time" content="2026-03-10T06:00:00+00:00"><meta property="article:modified_time" content="2026-05-01T07:00:00+00:00">',
        );

        $this->assertSame([], $parsed['schema_mismatch']);
    }

    public function test_conflicting_dates_are_listed(): void
    {
        $parsed = $this->parse(
            ['datePublished' => '2026-03-10', 'dateModified' => '2025-01-01'],
            '',
            '<time datetime="2026-04-02">2 Nisan 2026</time>',
        );

        $this->assertSame([
            'Şemadaki yayın tarihi (10.03.2026) sayfadakinden (02.04.2026) farklı',
            'Şemada güncelleme tarihi (01.01.2025) yayın tarihinden (10.03.2026) önce',
        ], $parsed['schema_mismatch']);
    }

    public function test_future_publication_date_is_listed(): void
    {
        $parsed = $this->parse(['datePublished' => now()->addMonth()->toDateString()]);

        $this->assertSame(['Şemadaki yayın tarihi ('.now()->addMonth()->format('d.m.Y').') ileri bir tarih'], $parsed['schema_mismatch']);
    }
}
