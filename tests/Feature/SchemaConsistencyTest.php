<?php

namespace Tests\Feature;

use App\Crawler\HtmlParser;
use Tests\TestCase;

class SchemaConsistencyTest extends TestCase
{
    private function parse(array $graph, string $body): array
    {
        $ld = '<script type="application/ld+json">'.json_encode(['@context' => 'https://schema.org', '@graph' => $graph]).'</script>';

        return HtmlParser::parse("<html><head><title>Diş Beyazlatma Fiyatları | Klinik</title>{$ld}</head><body>{$body}</body></html>", 'https://example.com/a');
    }

    public function test_matching_markup_passes(): void
    {
        $parsed = $this->parse([
            ['@type' => 'Product', '@id' => '#urun', 'name' => 'Diş Beyazlatma', 'offers' => ['@type' => 'Offer', 'price' => '1500.00', 'priceCurrency' => 'TRY']],
            ['@type' => 'BlogPosting', 'headline' => 'Diş beyazlatma fiyatları', 'about' => ['@id' => '#urun']],
        ], '<h1>Diş Beyazlatma</h1><p>Fiyat: 1.500,00 TL</p>');

        $this->assertSame([], $parsed['schema_mismatch']);
    }

    public function test_disagreements_are_listed(): void
    {
        $parsed = $this->parse([
            ['@type' => 'Product', 'name' => 'Ortodonti Paketi', 'offers' => ['@type' => 'Offer', 'price' => 999, 'priceCurrency' => 'TRY']],
            ['@type' => 'WebPage', 'isPartOf' => ['@id' => '#website'], 'publisher' => ['@id' => 'https://example.com/#organization']],
        ], '<h1>Diş Beyazlatma</h1><p>Fiyat: 1.500 TL</p>');

        $this->assertSame([
            'Şemadaki başlık sayfada yok: "Ortodonti Paketi"',
            'Şemadaki fiyat (999) sayfada görünmüyor',
            'Kırık @id bağlantısı: #website',
        ], $parsed['schema_mismatch']);
    }
}
