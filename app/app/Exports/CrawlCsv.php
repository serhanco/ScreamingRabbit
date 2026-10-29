<?php

namespace App\Exports;

use App\Audit\RuleCatalog;
use App\Models\Crawl;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a crawl's URLs or issues as CSV (UTF-8 with BOM and ';' separator so Excel opens Turkish text correctly).
 */
class CrawlCsv
{
    private const PAGE_COLUMNS = [
        'url' => 'URL', 'status_code' => 'Durum kodu', 'state' => 'Durum', 'depth' => 'Derinlik', 'in_sitemap' => 'Sitemap',
        'title' => 'Title', 'meta_description' => 'Meta description', 'h1' => 'H1', 'h1_count' => 'H1 sayısı',
        'canonical' => 'Canonical', 'schema_types' => 'Şema türleri', 'meta_robots' => 'Meta robots', 'x_robots' => 'X-Robots-Tag', 'word_count' => 'Kelime',
        'inlinks' => 'Gelen link', 'link_score' => 'Link puanı', 'response_ms' => 'Yanıt (ms)', 'size_bytes' => 'Boyut (bayt)', 'content_type' => 'İçerik türü',
        'redirect_to' => 'Yönlendirme hedefi', 'error' => 'Hata',
    ];

    public function __construct(private Crawl $crawl) {}

    public function pages(): StreamedResponse
    {
        return $this->stream('urller', array_values(self::PAGE_COLUMNS), function ($out) {
            $this->crawl->pages()->orderBy('id')->chunkById(500, function ($pages) use ($out) {
                foreach ($pages as $page) {
                    fputcsv($out, array_map(fn ($col) => $this->cell($page->{$col}), array_keys(self::PAGE_COLUMNS)), ';');
                }
            });
        });
    }

    public function issues(): StreamedResponse
    {
        $catalog = RuleCatalog::all();

        return $this->stream('sorunlar', ['Önem', 'Kategori', 'Sorun', 'URL', 'Ayrıntı', 'Çözüm önerisi'], function ($out) use ($catalog) {
            $this->crawl->issues()
                ->leftJoin('crawl_pages', 'crawl_pages.id', '=', 'crawl_issues.page_id')
                ->select('crawl_issues.id', 'crawl_issues.rule', 'crawl_issues.severity', 'crawl_issues.detail', 'crawl_pages.url')
                ->chunkById(500, function ($issues) use ($out, $catalog) {
                    foreach ($issues as $issue) {
                        $rule = $catalog[$issue->rule] ?? ['title' => $issue->rule, 'category' => '', 'fix' => ''];
                        fputcsv($out, [
                            RuleCatalog::SEVERITIES[$issue->severity] ?? $issue->severity,
                            RuleCatalog::CATEGORIES[$rule['category']] ?? '',
                            $rule['title'],
                            $this->cell($issue->url),
                            $this->cell($issue->detail),
                            $rule['fix'],
                        ], ';');
                    }
                }, 'crawl_issues.id', 'id');
        });
    }

    private function stream(string $kind, array $header, callable $rows): StreamedResponse
    {
        $name = Str::slug($this->crawl->loadMissing('project')->project->name ?? 'tarama').'-'.$kind.'-'.$this->crawl->id.'.csv';

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';');
            $rows($out);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Booleans as Evet/Hayır, and a leading quote on formula-like text so spreadsheets never run it. */
    private function cell(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Evet' : 'Hayır';
        }

        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
