<?php

namespace Tests\Feature;

use App\Crawler\HtmlParser;
use Tests\TestCase;

class RdfaTypesTest extends TestCase
{
    public function test_rdfa_types_are_collected(): void
    {
        $html = '<html><head><title>Yazı</title></head><body>'
            .'<article vocab="https://schema.org/" typeof="BlogPosting"><h1 property="headline">Yazı</h1>'
            .'<div property="author" typeof="Person"><span property="name">Ayşe</span></div></article>'
            .'<div prefix="schema: http://schema.org/" typeof="schema:LocalBusiness"></div>'
            .'<div typeof="http://schema.org/Event"></div>'
            .'<div typeof="foaf:Person"></div><div typeof="Thing"></div>'
            .'</body></html>';

        $parsed = HtmlParser::parse($html, 'https://example.com/yazi');

        $this->assertEqualsCanonicalizing(['BlogPosting', 'Person', 'LocalBusiness', 'Event'], $parsed['schema_types']);
    }
}
