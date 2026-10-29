<?php

namespace Tests\Feature;

use App\Audit\SchemaVocabulary;
use App\Crawler\HtmlParser;
use Tests\TestCase;

class SchemaVocabularyTest extends TestCase
{
    public function test_terms_are_checked_against_schema_org(): void
    {
        $valid = ['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'Dentist', 'name' => 'Klinik', 'telephone' => '1', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'a'], 'openingHours' => 'Mo-Fr', 'priceRange' => '₺₺'],
            ['@type' => 'BlogPosting', 'headline' => 'h', 'author' => ['@type' => 'Person', 'name' => 'a'], 'datePublished' => '2026-01-01', 'publisher' => ['@id' => '#org'], 'wordCount' => 500],
            ['@type' => 'Product', 'name' => 'p', 'offers' => ['@type' => 'Offer', 'price' => '10', 'priceCurrency' => 'TRY', 'availability' => 'https://schema.org/InStock']],
            ['@type' => 'WebSite', 'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => 'x'], 'query-input' => 'required name=s']],
            ['@type' => 'https://schema.org/FAQPage', 'mainEntity' => [['@type' => 'Question', 'name' => 'q', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'a']]]],
        ]];
        $this->assertSame([], SchemaVocabulary::problems([$valid]));

        $broken = ['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'Prodcut', 'name' => 'x'],
            ['@type' => 'Product', 'headline' => 'x', 'colour' => 'red', 'name' => ['@type' => 'Thing', 'name' => 'x']],
        ]];
        $this->assertSame([
            'Bilinmeyen tür: Prodcut',
            'Product türünde geçersiz özellik: headline',
            'Bilinmeyen özellik: Product.colour',
            'Yanlış değer tipi: Product.name metin, sayı, tarih veya adres olmalı',
        ], SchemaVocabulary::problems([$broken]));

        $this->assertSame([], SchemaVocabulary::problems([['@context' => 'https://example.org/vocab', '@type' => 'Widget']]), 'other vocabularies are left alone');

        $parsed = HtmlParser::parse('<html><head><script type="application/ld+json">'.json_encode($broken).'</script></head><body></body></html>', 'https://example.com/');
        $this->assertContains('Bilinmeyen tür: Prodcut', $parsed['schema_vocabulary']);
    }
}
