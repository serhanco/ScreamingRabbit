<?php

namespace App\Audit;

use App\Models\Crawl;

/**
 * Site-wide structured data table for one crawl: each schema.org type, on how many pages it appears, and on how many
 * of those it misses required or recommended rich-result properties.
 */
class SchemaInventory
{
    /**
     * @return array<int, array{type: string, pages: int, required: int, recommended: int, duplicates: int}>
     */
    public static function for(Crawl $crawl): array
    {
        $rows = [];
        $query = $crawl->pages()->where('state', 'done')->whereNotNull('schema_types')->select(['id', 'schema_types', 'schema_problems', 'schema_duplicates']);
        foreach ($query->lazyById(1000) as $page) {
            $problems = collect($page->schema_problems ?? []);
            $duplicates = array_filter(explode(',', (string) $page->schema_duplicates));
            foreach (array_filter(explode(',', $page->schema_types)) as $type) {
                $row = $rows[$type] ?? ['type' => $type, 'pages' => 0, 'required' => 0, 'recommended' => 0, 'duplicates' => 0];
                $row['pages']++;
                $own = $problems->where('type', $type);
                $row['required'] += $own->contains(fn (array $p) => $p['required'] !== []) ? 1 : 0;
                $row['recommended'] += $own->contains(fn (array $p) => $p['recommended'] !== []) ? 1 : 0;
                $row['duplicates'] += in_array(SchemaRules::ruleType($type), $duplicates, true) ? 1 : 0;
                $rows[$type] = $row;
            }
        }

        return collect($rows)->sortByDesc('pages')->values()->all();
    }
}
