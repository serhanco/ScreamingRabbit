<?php

namespace App\Audit;

use App\Models\CrawlPage;

/**
 * How ready a page is to be read and quoted by AI search (Google AI Overviews, ChatGPT, Perplexity, Gemini):
 * the share of the signals below that the page meets, 0-100. Date and author only count for articles.
 */
class AiReadiness
{
    /** Signal => [label, weight]. */
    public const SIGNALS = [
        'readable' => ['Metin JavaScript olmadan okunuyor', 15],
        'schema' => ['Yapısal veri var', 15],
        'schema_valid' => ['Şemada zorunlu alan eksiği yok', 10],
        'questions' => ['Soru biçimli başlık veya SSS şeması', 10],
        'outline' => ['H2 bölümleri var, başlık sırası doğru', 15],
        'passages' => ['Paragraflar kısa (alıntılanabilir)', 10],
        'depth' => ['En az 300 kelime içerik', 10],
        'date' => ['Yayın/güncelleme tarihi', 8],
        'author' => ['Yazar bilgisi', 7],
    ];

    /**
     * @return array<string, bool> signal => met, only the signals that apply to the page
     */
    public static function signals(CrawlPage $page): array
    {
        $types = array_filter(explode(',', (string) $page->schema_types));
        $signals = [
            'readable' => ! $page->js_shell,
            'schema' => $types !== [],
            'schema_valid' => $types !== [] && (int) $page->schema_errors === 0 && collect($page->schema_problems ?? [])->every(fn (array $p) => $p['required'] === []),
            'questions' => $page->question_headings > 0 || in_array('FAQPage', $types, true),
            'outline' => $page->h2_count > 0 && $page->heading_skip === null,
            'passages' => $page->long_paragraphs < 2,
            'depth' => $page->word_count >= 300,
        ];
        if ($page->is_article || $page->wp_type === 'post') {
            $signals['date'] = $page->published_at !== null || $page->modified_at !== null;
            $signals['author'] = (bool) $page->has_author;
        }

        return $signals;
    }

    public static function score(CrawlPage $page): int
    {
        $earned = $possible = 0;
        foreach (self::signals($page) as $signal => $met) {
            $possible += self::SIGNALS[$signal][1];
            $earned += $met ? self::SIGNALS[$signal][1] : 0;
        }

        return (int) round($earned / $possible * 100);
    }
}
