<?php

namespace App\Exports;

use App\Models\Crawl;
use App\Models\CrawlPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An XML sitemap built from a crawl: only indexable pages (200, HTML, no noindex, self canonical), with the last
 * update date when the page states one and its working images (Google image sitemap extension).
 */
class CrawlSitemap
{
    /** The sitemap protocol's limits per file. */
    public const MAX_URLS = 50000;

    private const MAX_IMAGES_PER_URL = 1000;

    public function __construct(private Crawl $crawl) {}

    public function download(): StreamedResponse
    {
        $name = Str::slug($this->crawl->loadMissing('project')->project->name ?? 'site').'-sitemap.xml';

        return response()->streamDownload(fn () => $this->write(fopen('php://output', 'w')), $name, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * @param  resource  $out
     */
    public function write($out): int
    {
        fwrite($out, '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n");

        $written = 0;
        $this->crawl->pages()->where('state', 'done')->where('status_code', 200)->where('content_type', 'like', '%html%')
            ->orderBy('depth')->orderBy('id')
            ->chunkById(500, function ($pages) use ($out, &$written) {
                $pages = $pages->filter(fn (CrawlPage $page) => $page->isIndexable());
                $images = DB::table('crawl_image_refs')->join('crawl_images', 'crawl_images.id', '=', 'crawl_image_refs.image_id')
                    ->whereIn('crawl_image_refs.page_id', $pages->pluck('id'))->where('crawl_images.status_code', 200)
                    ->select('crawl_image_refs.page_id', 'crawl_images.url')->distinct()->get()->groupBy('page_id');

                foreach ($pages as $page) {
                    if ($written >= self::MAX_URLS) {
                        return false;
                    }
                    $entry = '  <url><loc>'.$this->escape($page->url).'</loc>';
                    if ($updated = $page->modified_at ?? $page->published_at) {
                        $entry .= '<lastmod>'.$updated->toDateString().'</lastmod>';
                    }
                    foreach (($images[$page->id] ?? collect())->take(self::MAX_IMAGES_PER_URL) as $image) {
                        $entry .= '<image:image><image:loc>'.$this->escape($image->url).'</image:loc></image:image>';
                    }
                    fwrite($out, $entry."</url>\n");
                    $written++;
                }
            });

        fwrite($out, "</urlset>\n");

        return $written;
    }

    private function escape(string $url): string
    {
        return htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
