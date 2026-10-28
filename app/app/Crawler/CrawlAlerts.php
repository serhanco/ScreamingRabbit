<?php

namespace App\Crawler;

use App\Audit\RuleCatalog;
use App\Models\Crawl;
use App\Monitoring\Alerts;

/**
 * Alerts raised by crawls (docs/08 §3.1): a crawl that failed, and high-severity problems the previous crawl did not have.
 */
class CrawlAlerts
{
    public static function failed(Crawl $crawl): void
    {
        Alerts::send('crawl_failed', 'Tarama hata verdi: '.$crawl->project->name, $crawl->project->base_url."\n".($crawl->error ?? 'Bilinmeyen hata'), $crawl->project_id);
    }

    public static function newIssues(Crawl $crawl): void
    {
        $data = (new CrawlComparison($crawl))->data();
        $high = collect($data['new_issues'] ?? [])->where('severity', 'high');
        if ($high->isEmpty()) {
            return;
        }

        $catalog = RuleCatalog::all();
        $lines = $high->map(fn ($row) => '• '.($catalog[$row->rule]['title'] ?? $row->rule).': '.$row->pages.' sayfa')->implode("\n");
        Alerts::send('new_issue', 'Yeni yüksek önemli sorun: '.$crawl->project->name,
            "Önceki taramada olmayan sorunlar ({$crawl->project->base_url}):\n{$lines}", $crawl->project_id);
    }
}
