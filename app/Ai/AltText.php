<?php

namespace App\Ai;

use App\Crawler\UrlNormalizer;
use App\Models\Project;
use App\Models\User;
use App\Models\WpChange;
use App\WordPress\Changes;
use App\WordPress\Connector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * AI alt text for a media library image (docs/09 Faz 7, AI actions): the model sees the image itself and the pages that
 * use it (last crawl), and its answer becomes an alt text proposal in the WordPress change list, applied only on approval.
 */
class AltText
{
    public const MAX = 125;

    /** Image types every provider reads, and the largest file sent (providers refuse bigger ones). */
    private const TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const MAX_BYTES = 4 * 1024 * 1024;

    private const SYSTEM = <<<'TXT'
        You write the alt text for one image on a web page, for people using screen readers and for search engines.
        Write in the language of the pages that use the image. Describe what the image shows that matters in the page's
        context, in one plain sentence of at most 125 characters. Do not start with "image of" or "photo of", do not add
        keywords that the image does not show, and never guess names of people, places or brands that are not visible
        or given in the page data. For a purely decorative image answer with an empty alt.
        Reply with only a JSON object: {"alt": "...", "reason": "..."} where reason explains in one short Turkish sentence
        what the alt text is based on.
        TXT;

    /** @return WpChange|string the proposal, or why there is none */
    public static function propose(Project $project, string $url, User $user): WpChange|string
    {
        $connection = $project->wpConnection;
        if ($connection === null || ! $connection->isConnected()) {
            return 'Proje WordPress eklentisine bağlı değil.';
        }
        if (! Connector::sameSite($url, $project->base_url)) {
            return 'Görsel bu sitede olmalı.';
        }
        $current = Changes::current($connection, $url);
        if (isset($current['error'])) {
            return $current['error'];
        }
        if (! array_key_exists('alt', $current['values'])) {
            return 'Bu adreste medya kütüphanesinde bir görsel yok.';
        }

        $image = self::download($url);
        if (is_string($image)) {
            return $image;
        }

        try {
            $result = Ai::ask('alt_text', self::SYSTEM, self::prompt($project, $url, (string) $current['values']['alt']), $project->id, 300, [$image]);
        } catch (AiException $e) {
            return $e->getMessage();
        }
        $answer = self::parse($result->text);
        if ($answer === null) {
            Ai::discard($result);

            return 'AI cevabı okunamadı; tekrar deneyin.';
        }

        return Changes::propose($project, $url, 'alt', $answer['alt'], $user, 'ai', 'AI önerisi ('.$result->model.'): '.$answer['reason']);
    }

    /** @return array{mime: string, data: string}|string */
    private static function download(string $url): array|string
    {
        try {
            $response = Http::timeout(20)->withOptions(['allow_redirects' => false])->get($url);
        } catch (\Throwable) {
            return 'Görsel indirilemedi.';
        }
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if (! $response->successful() || ! in_array($mime, self::TYPES, true)) {
            return 'Görsel okunamadı (JPEG, PNG, GIF veya WebP olmalı).';
        }
        if (strlen($response->body()) > self::MAX_BYTES) {
            return 'Görsel 4 MB\'tan büyük; küçük boyutlu kopyasının adresini girin (ör. …-1024x768.jpg).';
        }

        return ['mime' => $mime, 'data' => base64_encode($response->body())];
    }

    private static function prompt(Project $project, string $url, string $currentAlt): string
    {
        $lines = ['Image URL: '.$url, 'Current alt text: '.($currentAlt === '' ? '(empty)' : $currentAlt)];

        $crawl = $project->crawls()->where('status', 'completed')->latest('finished_at')->first();
        $pages = $crawl === null ? collect() : DB::table('crawl_image_refs')
            ->join('crawl_images', 'crawl_images.id', '=', 'crawl_image_refs.image_id')
            ->join('crawl_pages', 'crawl_pages.id', '=', 'crawl_image_refs.page_id')
            ->where('crawl_image_refs.crawl_id', $crawl->id)->where('crawl_images.url_hash', UrlNormalizer::hash($url))
            ->limit(3)->get(['crawl_pages.url', 'crawl_pages.title', 'crawl_pages.h1']);
        foreach ($pages as $page) {
            $lines[] = 'Used on: '.$page->url.' | title: '.($page->title ?? '-').' | H1: '.($page->h1 ?? '-');
        }
        if ($pages->isEmpty()) {
            $lines[] = 'No page of the last crawl uses this exact file; describe what the image shows.';
        }

        return implode("\n", $lines);
    }

    /** @return array{alt: string, reason: string}|null */
    public static function parse(string $text): ?array
    {
        if (! preg_match('/\{.*\}/s', $text, $match)) {
            return null;
        }
        $data = json_decode($match[0], true);
        if (! is_array($data) || ! is_string($data['alt'] ?? null)) {
            return null;
        }

        return ['alt' => mb_substr(trim($data['alt']), 0, 300), 'reason' => mb_substr(trim((string) ($data['reason'] ?? '')), 0, 400)];
    }
}
