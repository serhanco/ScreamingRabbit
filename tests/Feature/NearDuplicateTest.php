<?php

namespace Tests\Feature;

use App\Audit\Auditor;
use App\Audit\SimHash;
use App\Crawler\HtmlParser;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NearDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private function text(string $city, int $seed = 1): string
    {
        mt_srand($seed);
        $words = ['implant', 'diş', 'tedavi', 'klinik', 'hekim', 'randevu', 'fiyat', 'estetik', 'kanal', 'dolgu', 'beyazlatma', 'ortodonti', 'çekim', 'protez', 'muayene', 'hasta', 'ağız', 'sağlık', 'gülüş', 'kaplama'];
        $out = [];
        for ($i = 0; $i < 300; $i++) {
            $out[] = $words[mt_rand(0, count($words) - 1)];
        }

        return "{$city} diş kliniği. ".implode(' ', $out);
    }

    public function test_fingerprints_tell_near_copies_from_different_pages(): void
    {
        $kadikoy = SimHash::of($this->text('Kadıköy'));
        $besiktas = SimHash::of($this->text('Beşiktaş'));
        $other = SimHash::of($this->text('Kadıköy', 2));

        $this->assertLessThanOrEqual(SimHash::MAX_DISTANCE, SimHash::distance($kadikoy, $besiktas), 'same template, only the city changes');
        $this->assertGreaterThan(SimHash::MAX_DISTANCE, SimHash::distance($kadikoy, $other));
        $this->assertNull(SimHash::of('kısa metin'), 'too short to compare');
        $this->assertSame([1 => [2], 2 => [1]], SimHash::nearDuplicates([1 => $kadikoy, 2 => $besiktas, 3 => $other]));
    }

    public function test_menus_and_footers_are_left_out_of_the_fingerprint(): void
    {
        $menu = '<nav>'.$this->text('Menü', 5).'</nav><footer>'.$this->text('Alt', 6).'</footer>';
        $a = HtmlParser::parse("<html><body>{$menu}<p>Kısa sayfa</p></body></html>", 'https://example.com/a');
        $b = HtmlParser::parse("<html><body>{$menu}<p>{$this->text('Kadıköy')}</p></body></html>", 'https://example.com/b');

        $this->assertNull($a['simhash'], 'only the shared menu and footer, no main text of its own');
        $this->assertSame(SimHash::of($this->text('Kadıköy')), $b['simhash']);
    }

    public function test_near_duplicates_are_reported_but_exact_copies_are_not_doubled(): void
    {
        $project = Project::factory()->create();
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'completed', 'settings' => []]);
        $page = fn (string $path, string $text) => CrawlPage::create(['crawl_id' => $crawl->id, 'url' => 'https://example.com'.$path, 'url_hash' => sha1($path), 'state' => 'done',
            'status_code' => 200, 'content_type' => 'text/html', 'canonical' => 'https://example.com'.$path, 'word_count' => 300, 'content_hash' => md5($text), 'simhash' => SimHash::of($text)]);

        $kadikoy = $page('/kadikoy', $this->text('Kadıköy'));
        $page('/besiktas', $this->text('Beşiktaş'));
        $copy = $page('/kopya', $this->text('Kadıköy'));
        $page('/blog', $this->text('Blog', 3));

        (new Auditor)->run($crawl);

        $this->assertSame('https://example.com/besiktas', $kadikoy->issues()->where('rule', 'near_duplicate_content')->value('detail'));
        $this->assertContains('duplicate_content', $copy->issues()->pluck('rule')->all());
        $this->assertSame(3, $crawl->issues()->where('rule', 'near_duplicate_content')->count(), 'Kadıköy, its exact copy and Beşiktaş; not the blog post');
    }
}
