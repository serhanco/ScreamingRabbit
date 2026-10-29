<?php

namespace App\Ai;

use App\Crawler\UrlNormalizer;
use App\Models\Project;
use App\Models\User;
use App\Models\WpChange;
use App\WordPress\Changes;
use Illuminate\Support\Facades\DB;

/**
 * AI title and meta description suggestions for a page (docs/09 Faz 7, AI actions). The model sees what the page is
 * about (last crawl: title, description, H1, length) and what people search to find it (Search Console queries), and
 * the result becomes ordinary proposals in the WordPress change list: nothing reaches the site until someone approves.
 */
class TitleMeta
{
    public const TITLE_MAX = 60;

    public const DESCRIPTION_MAX = 155;

    private const SYSTEM = <<<'TXT'
        You are an SEO specialist writing the HTML title and meta description for one web page.
        Write in the language the page is written in. Use only facts present in the page data; never invent prices, dates,
        numbers, awards or claims. Match the search intent of the queries people already use to find the page, putting the
        main topic early. Title: at most 60 characters, specific, no keyword stuffing, the site or brand name at the end
        only if it already appears in the current title. Description: 120 to 155 characters, a concrete summary of what the
        page offers and a reason to click, no quotation marks. Do not use template variables.
        Reply with only a JSON object: {"title": "...", "description": "...", "reason": "..."} where reason explains in
        one or two short Turkish sentences what you changed and why.
        TXT;

    /**
     * Asks the AI and saves its title and description as proposals.
     *
     * @return list<WpChange>|string the proposals, or why there are none
     */
    public static function propose(Project $project, string $url, User $user): array|string
    {
        $connection = $project->wpConnection;
        if ($connection === null || ! $connection->isConnected()) {
            return 'Proje WordPress eklentisine bağlı değil.';
        }
        $current = Changes::current($connection, $url);
        if (isset($current['error'])) {
            return $current['error'];
        }
        if (! array_key_exists('title', $current['values'])) {
            return 'Bu adreste yayında bir yazı veya sayfa yok (kategori, arşiv ve ürün listesi sayfaları değiştirilemez).';
        }

        try {
            $result = Ai::ask('title_meta', self::SYSTEM, self::prompt($project, $url), $project->id, 600);
        } catch (AiException $e) {
            return $e->getMessage();
        }
        $answer = self::parse($result->text);
        if ($answer === null) {
            Ai::discard($result);

            return 'AI cevabı okunamadı; tekrar deneyin.';
        }

        $note = 'AI önerisi ('.$result->model.'): '.$answer['reason'];
        $changes = [];
        $problems = [];
        foreach (['title', 'description'] as $field) {
            $change = Changes::propose($project, $url, $field, $answer[$field], $user, 'ai', $note);
            $change instanceof WpChange ? $changes[] = $change : $problems[] = WpChange::FIELDS[$field].': '.$change;
        }

        return $changes === [] ? implode(' ', $problems) : $changes;
    }

    private static function prompt(Project $project, string $url): string
    {
        $hash = UrlNormalizer::hash($url);
        $crawl = $project->crawls()->where('status', 'completed')->latest('finished_at')->first();
        $page = $crawl?->pages()->where('url_hash', $hash)->first();

        $lines = ['URL: '.$url];
        if ($page !== null) {
            $lines[] = 'Current title: '.($page->title ?? '(none)');
            $lines[] = 'Current meta description: '.($page->meta_description ?? '(none)');
            $lines[] = 'H1: '.($page->h1 ?? '(none)');
            $lines[] = 'Word count: '.(int) $page->word_count;
        } else {
            $lines[] = 'The page is not in the last crawl; infer the topic from the URL and the queries.';
        }

        $queries = DB::table('gsc_queries')->where('project_id', $project->id)->where('page_hash', $hash)
            ->orderByDesc('impressions')->limit(15)->get(['query', 'clicks', 'impressions', 'position']);
        if ($queries->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Google Search Console queries for this page (query | clicks | impressions | average position):';
            foreach ($queries as $query) {
                $lines[] = $query->query.' | '.$query->clicks.' | '.$query->impressions.' | '.round((float) $query->position, 1);
            }
        }

        return implode("\n", $lines);
    }

    /** @return array{title: string, description: string, reason: string}|null */
    public static function parse(string $text): ?array
    {
        if (! preg_match('/\{.*\}/s', $text, $match)) {
            return null;
        }
        $data = json_decode($match[0], true);
        if (! is_array($data) || ! is_string($data['title'] ?? null) || ! is_string($data['description'] ?? null)) {
            return null;
        }
        $title = trim($data['title']);
        $description = trim($data['description']);
        if ($title === '' || $description === '') {
            return null;
        }

        return [
            'title' => mb_substr($title, 0, 300),
            'description' => mb_substr($description, 0, 1000),
            'reason' => mb_substr(trim((string) ($data['reason'] ?? '')), 0, 400),
        ];
    }
}
