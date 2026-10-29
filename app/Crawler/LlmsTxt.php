<?php

namespace App\Crawler;

/**
 * Checks /llms.txt against the llmstxt.org format: an H1 title first, then an optional "> summary"
 * and "## " sections with Markdown link lists.
 */
class LlmsTxt
{
    /** Links kept for checking; a long llms.txt lists hundreds. */
    public const MAX_URLS = 50;

    /**
     * @return array{status: string, title: ?string, summary: bool, links: int, urls: array<int, string>}
     */
    public static function analyze(?string $body): array
    {
        $result = ['status' => 'missing', 'title' => null, 'summary' => false, 'links' => 0, 'urls' => []];

        $body = trim((string) $body);
        // Many sites answer every path with their HTML page, so a 200 alone does not mean the file exists.
        if ($body === '' || preg_match('/^\s*(<!doctype|<html|<\?xml)/i', $body)) {
            return $result;
        }

        $lines = preg_split('/\R/', $body);
        $first = trim($lines[0]);

        $result['title'] = str_starts_with($first, '# ') ? mb_substr(trim(substr($first, 2)), 0, 200) : null;
        $result['summary'] = (bool) preg_match('/^>\s*\S/m', $body);
        $result['links'] = preg_match_all('/\[[^\]]+\]\(((?:https?:\/\/|\/)[^)\s]+)[^)]*\)/', $body, $matches);
        $result['urls'] = array_slice(array_values(array_unique($matches[1])), 0, self::MAX_URLS);
        $result['status'] = $result['title'] !== null && $result['links'] > 0 ? 'ok' : 'invalid';

        return $result;
    }
}
