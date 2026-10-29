<?php

namespace App\Crawler;

/**
 * Minimal robots.txt reader: picks the group whose User-agent equals the bot's product token (or "*"),
 * longest matching rule wins, Allow wins ties (RFC 9309).
 */
class RobotsTxt
{
    /** @var array<int, array{allow: bool, pattern: string}> */
    private array $rules = [];

    /** @var array<int, string> */
    public array $sitemaps = [];

    /** The User-agent line whose group applies to this bot ("*" when only the general group matches), null when none does. */
    public ?string $group = null;

    public function __construct(string $contents, string $agentToken = 'screamingrabbit')
    {
        $groups = [];
        $current = null;
        $lastWasAgent = false;

        foreach (preg_split('/\r\n|\r|\n/', $contents) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap') {
                $this->sitemaps[] = $value;
            } elseif ($field === 'user-agent') {
                if (! $lastWasAgent) {
                    $current = count($groups);
                    $groups[$current] = ['agents' => [], 'rules' => []];
                }
                $groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;

                continue;
            } elseif (in_array($field, ['allow', 'disallow'], true) && $current !== null && $value !== '') {
                $groups[$current]['rules'][] = ['allow' => $field === 'allow', 'pattern' => $value];
            }
            $lastWasAgent = false;
        }

        $chosen = null;
        foreach ($groups as $group) {
            foreach ($group['agents'] as $agent) {
                if ($agent !== '*' && $agent === strtolower($agentToken)) {
                    $chosen = $group;
                    break 2;
                }
            }
        }
        $this->group = $chosen !== null ? strtolower($agentToken) : null;
        $chosen ??= collect($groups)->first(fn ($g) => in_array('*', $g['agents'], true));
        $this->group ??= $chosen !== null ? '*' : null;

        $this->rules = $chosen['rules'] ?? [];
    }

    public function isAllowed(string $url): bool
    {
        return $this->decide($url)['allowed'];
    }

    /**
     * @return array{allowed: bool, rule: ?string} the verdict and the rule that decided it ("Disallow: /wp-admin/"), null when no rule matched
     */
    public function decide(string $url): array
    {
        $path = (parse_url($url, PHP_URL_PATH) ?? '/').(($q = parse_url($url, PHP_URL_QUERY)) ? '?'.$q : '');
        $bestLength = -1;
        $best = null;

        foreach ($this->rules as $rule) {
            if (! $this->matches($rule['pattern'], $path)) {
                continue;
            }
            $length = strlen($rule['pattern']);
            if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                $bestLength = $length;
                $best = $rule;
            }
        }

        return [
            'allowed' => $best['allow'] ?? true,
            'rule' => $best === null ? null : ($best['allow'] ? 'Allow: ' : 'Disallow: ').$best['pattern'],
        ];
    }

    private function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $regex = '#^'.str_replace('\*', '.*', preg_quote(rtrim($pattern, '$'), '#')).($anchored ? '$' : '').'#';

        return (bool) preg_match($regex, $path);
    }
}
