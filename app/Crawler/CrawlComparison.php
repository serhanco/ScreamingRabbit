<?php

namespace App\Crawler;

use App\Models\Crawl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What changed between a crawl and the previous completed crawl of the same project (docs/09 Faz 4):
 * new and lost pages, status and on-page changes, new and fixed problems, health score movement.
 * Done in SQL on the URL hash so thousands of pages stay cheap.
 */
class CrawlComparison
{
    public const LIMIT = 20;

    /** Page fields whose change is worth showing, with their label. */
    public const FIELDS = ['status_code' => 'Durum kodu', 'title' => 'Title', 'meta_description' => 'Meta description', 'canonical' => 'Canonical', 'meta_robots' => 'Meta robots'];

    private ?Crawl $previous = null;

    public function __construct(private Crawl $crawl)
    {
        $this->previous = Crawl::query()->where('project_id', $crawl->project_id)->where('status', 'completed')
            ->where('id', '<', $crawl->id)->latest('id')->first();
    }

    public function previous(): ?Crawl
    {
        return $this->previous;
    }

    /**
     * @return array<string, mixed>|null null when there is no earlier crawl to compare with
     */
    public function data(): ?array
    {
        if (! $this->previous || $this->crawl->status !== 'completed') {
            return null;
        }

        $new = $this->newPages();
        $lost = $this->lostPages();
        $changed = $this->changedPages();
        $newIssues = $this->issueGroups($this->crawl, $this->previous);
        $fixedIssues = $this->issueGroups($this->previous, $this->crawl);

        return [
            'previous_date' => ($this->previous->finished_at ?? $this->previous->created_at)->timezone(config('app.timezone'))->format('d.m.Y'),
            'score' => [$this->previous->health_score, $this->crawl->health_score],
            'new_pages' => $new,
            'lost_pages' => $lost,
            'changed' => $changed,
            'new_issues' => $newIssues,
            'fixed_issues' => $fixedIssues,
            'capped' => $this->hitLimit($this->crawl) || $this->hitLimit($this->previous),
        ];
    }

    private function donePages(Crawl $crawl)
    {
        return DB::table('crawl_pages')->where('crawl_pages.crawl_id', $crawl->id)->where('crawl_pages.state', 'done');
    }

    /**
     * @return array{count: int, sample: Collection}
     */
    private function newPages(): array
    {
        return $this->absent($this->crawl, $this->previous);
    }

    /**
     * @return array{count: int, sample: Collection}
     */
    private function lostPages(): array
    {
        return $this->absent($this->previous, $this->crawl);
    }

    /**
     * Pages of $from that $other did not have.
     *
     * @return array{count: int, sample: Collection}
     */
    private function absent(Crawl $from, Crawl $other): array
    {
        $query = $this->donePages($from)
            ->whereNotIn('crawl_pages.url_hash', fn ($q) => $q->select('url_hash')->from('crawl_pages')->where('crawl_id', $other->id)->where('state', 'done'));

        return ['count' => (clone $query)->count(), 'sample' => $query->orderBy('crawl_pages.id')->limit(self::LIMIT)->get(['crawl_pages.url', 'crawl_pages.status_code'])];
    }

    /**
     * @return array<string, array{label: string, count: int, sample: Collection}>
     */
    private function changedPages(): array
    {
        $result = [];
        foreach (self::FIELDS as $field => $label) {
            $query = $this->donePages($this->crawl)
                ->join('crawl_pages as old', fn ($join) => $join->on('old.url_hash', '=', 'crawl_pages.url_hash')->where('old.crawl_id', $this->previous->id)->where('old.state', 'done'))
                ->whereRaw("COALESCE(crawl_pages.{$field}, '') <> COALESCE(old.{$field}, '')");
            $count = (clone $query)->count();
            if ($count > 0) {
                $result[$field] = [
                    'label' => $label,
                    'count' => $count,
                    'sample' => $query->orderBy('crawl_pages.id')->limit(self::LIMIT)
                        ->get(['crawl_pages.url', DB::raw("old.{$field} as before_value"), DB::raw("crawl_pages.{$field} as after_value")]),
                ];
            }
        }

        return $result;
    }

    /**
     * Problems present in $in but not in $notIn, matched on rule and page URL, grouped by rule.
     *
     * @return Collection<int, object{rule: string, severity: string, pages: int}>
     */
    private function issueGroups(Crawl $in, Crawl $notIn): Collection
    {
        $others = DB::table('crawl_issues')->where('crawl_issues.crawl_id', $notIn->id)
            ->join('crawl_pages', 'crawl_pages.id', '=', 'crawl_issues.page_id')
            ->select('crawl_issues.rule', 'crawl_pages.url_hash');

        return DB::table('crawl_issues')->where('crawl_issues.crawl_id', $in->id)
            ->join('crawl_pages', 'crawl_pages.id', '=', 'crawl_issues.page_id')
            // A page the other crawl never reached has no way to "have" the issue: only count pages both crawls saw.
            ->whereIn('crawl_pages.url_hash', fn ($q) => $q->select('url_hash')->from('crawl_pages')->where('crawl_id', $notIn->id)->where('state', 'done'))
            ->leftJoinSub($others, 'other', fn ($join) => $join->on('other.rule', '=', 'crawl_issues.rule')->on('other.url_hash', '=', 'crawl_pages.url_hash'))
            ->whereNull('other.rule')
            ->groupBy('crawl_issues.rule', 'crawl_issues.severity')
            ->selectRaw('crawl_issues.rule, crawl_issues.severity, COUNT(DISTINCT crawl_pages.id) as pages')
            ->orderByRaw("CASE crawl_issues.severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderByDesc('pages')
            ->get();
    }

    private function hitLimit(Crawl $crawl): bool
    {
        $max = (int) ($crawl->settings['max_urls'] ?? 0);

        return $max > 0 && $crawl->pages()->count() >= $max;
    }
}
