<?php

namespace App\Crawler;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Whether search engines and AI bots may crawl the site: robots.txt rules for each bot, plus one request to the
 * start page with each bot's User-Agent to spot firewalls (Cloudflare, WAF) that turn bots away.
 */
class BotAccess
{
    public const KINDS = [
        'search' => 'Arama motoru',
        'ai_search' => 'AI arama / asistan',
        'ai_training' => 'AI model eğitimi',
    ];

    /**
     * token => [name, kind, live]. "live" is false for tokens that only exist in robots.txt and never crawl.
     *
     * @var array<string, array{0: string, 1: string, 2: bool}>
     */
    public const BOTS = [
        'googlebot' => ['Googlebot (Google)', 'search', true],
        'bingbot' => ['Bingbot (Bing, Yahoo, DuckDuckGo sonuçları)', 'search', true],
        'yandexbot' => ['YandexBot (Yandex)', 'search', true],
        'duckduckbot' => ['DuckDuckBot (DuckDuckGo)', 'search', true],
        'baiduspider' => ['Baiduspider (Baidu)', 'search', true],
        'applebot' => ['Applebot (Siri, Spotlight)', 'search', true],
        'oai-searchbot' => ['OAI-SearchBot (ChatGPT arama)', 'ai_search', true],
        'chatgpt-user' => ['ChatGPT-User (ChatGPT kullanıcı isteği)', 'ai_search', true],
        'claude-searchbot' => ['Claude-SearchBot (Claude arama)', 'ai_search', true],
        'claude-user' => ['Claude-User (Claude kullanıcı isteği)', 'ai_search', true],
        'perplexitybot' => ['PerplexityBot (Perplexity arama)', 'ai_search', true],
        'perplexity-user' => ['Perplexity-User (Perplexity kullanıcı isteği)', 'ai_search', true],
        'gptbot' => ['GPTBot (OpenAI eğitim)', 'ai_training', true],
        'claudebot' => ['ClaudeBot (Anthropic eğitim)', 'ai_training', true],
        'google-extended' => ['Google-Extended (Gemini eğitim)', 'ai_training', false],
        'applebot-extended' => ['Applebot-Extended (Apple AI eğitim)', 'ai_training', false],
        'ccbot' => ['CCBot (Common Crawl)', 'ai_training', true],
        'meta-externalagent' => ['Meta-ExternalAgent (Meta AI)', 'ai_training', true],
        'bytespider' => ['Bytespider (ByteDance)', 'ai_training', true],
    ];

    private const REFUSED = [401, 403, 406, 429, 503];

    /**
     * Robots.txt verdict for the start URL of every bot; live checks are left "pending" for fetch().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fromRobots(?string $robotsTxt, string $startUrl): array
    {
        $rows = [];
        foreach (self::BOTS as $token => [$name, $kind, $live]) {
            $rows[$token] = [
                'name' => $name,
                'kind' => $kind,
                'robots_allowed' => (new RobotsTxt((string) $robotsTxt, $token))->isAllowed($startUrl),
                'fetch' => $live ? 'pending' : 'n/a',
                'status' => null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    public static function pending(array $rows): array
    {
        return array_keys(array_filter($rows, fn ($row) => $row['fetch'] === 'pending'));
    }

    /**
     * Requests the start URL as each given bot and records whether it was turned away and how much text it got.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<int, string>  $tokens
     * @return array<string, array<string, mixed>>
     */
    public static function fetch(array $rows, array $tokens, string $startUrl, int $timeout): array
    {
        $responses = Http::pool(fn (Pool $pool) => array_map(fn (string $token) => $pool->as($token)
            ->withUserAgent(self::userAgent($token))
            ->timeout($timeout)
            ->withoutRedirecting()
            ->get($startUrl), $tokens));

        foreach ($tokens as $token) {
            $response = $responses[$token] ?? null;
            $status = $response instanceof Response ? $response->status() : null;
            $rows[$token]['status'] = $status;
            $rows[$token]['fetch'] = match (true) {
                $status === null => 'error',
                in_array($status, self::REFUSED, true) => 'refused',
                default => 'ok',
            };
            // How much readable text the bot got, to spot challenge pages and cloaking that still answer 200.
            $rows[$token]['words'] = $status === 200 && str_contains(strtolower((string) $response->header('Content-Type')), 'html')
                ? HtmlParser::parse(mb_strcut($response->body(), 0, 2_000_000), $startUrl)['word_count']
                : null;
        }

        return $rows;
    }

    /**
     * Rows for screens and reports, with Turkish labels.
     *
     * @param  array<string, array<string, mixed>>|null  $rows
     * @return array<int, array{name: string, kind: string, robots: string, fetch: string, problem: bool}>
     */
    public static function present(?array $rows): array
    {
        return collect($rows ?? [])->map(fn ($row) => [
            'name' => $row['name'],
            'kind' => self::KINDS[$row['kind']] ?? $row['kind'],
            'robots' => $row['robots_allowed'] ? 'İzinli' : 'Engelli',
            'fetch' => match ($row['fetch']) {
                'ok' => 'Açıldı ('.$row['status'].(isset($row['words']) ? ', '.$row['words'].' kelime' : '').')',
                'refused' => 'Reddedildi ('.$row['status'].')',
                'error' => 'Yanıt yok',
                'pending' => 'Bekliyor',
                default => '—',
            },
            'problem' => ! $row['robots_allowed'] || $row['fetch'] === 'refused',
        ])->values()->all();
    }

    private static function userAgent(string $token): string
    {
        $product = strtok(self::BOTS[$token][0], ' ');

        return "Mozilla/5.0 (compatible; {$product}/1.0; ScreamingRabbit erişim testi)";
    }
}
