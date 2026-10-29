<?php

namespace Tests\Feature;

use App\Audit\Readability;
use App\Crawler\HtmlParser;
use Tests\TestCase;

class ReadabilityTest extends TestCase
{
    public function test_turkish_text_is_scored(): void
    {
        $easy = str_repeat('Ali okula gitti. Kedi süt içti. Hava çok güzel. ', 12);
        $hard = str_repeat('Kurumsal dijitalleştirme süreçlerinin sürdürülebilirliğinin değerlendirilmesinde uygulanabilir metodolojilerin karşılaştırmalı analizlerinin gerçekleştirilmesi organizasyonel performansın iyileştirilmesine katkı sağlamaktadır. ', 8);

        $this->assertGreaterThanOrEqual(70, Readability::atesman($easy));
        $this->assertLessThan(Readability::HARD, Readability::atesman($hard));
        $this->assertNull(Readability::atesman('Kısa metin.'));
        $this->assertSame('Çok zor', Readability::label(10));

        $page = fn (string $lang, string $text) => HtmlParser::parse('<html lang="'.$lang.'"><body><nav><p>Menü</p></nav><p>'.$text.'</p></body></html>', 'https://example.com/');
        $this->assertSame(Readability::atesman($hard), $page('tr-TR', $hard)['readability']);
        $this->assertNull($page('en', $hard)['readability']);
    }
}
