<?php

namespace App\Exports;

use App\Models\Crawl;
use App\Models\SpeedResult;
use App\Performance\SpeedScan;
use App\Segments\Segments;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel workbook of a site-wide speed scan: a summary sheet, then one sheet per device with a row per URL. Times are
 * plain numbers in milliseconds so they sort and filter in Excel.
 */
class SpeedXlsx
{
    private const COLUMNS = [
        'url' => 'URL', 'page_type' => 'Sayfa türü', 'score' => 'Performans puanı', 'cwv' => 'Core Web Vitals', 'cwv_from_field' => 'Değerlendirme kaynağı',
        'field_lcp' => 'LCP saha (ms)', 'field_inp' => 'INP saha (ms)', 'field_cls' => 'CLS saha', 'field_fcp' => 'FCP saha (ms)', 'field_ttfb' => 'TTFB saha (ms)',
        'lcp' => 'LCP lab (ms)', 'fcp' => 'FCP lab (ms)', 'tbt' => 'TBT lab (ms)', 'cls' => 'CLS lab', 'si' => 'Speed Index (ms)', 'ttfb' => 'Sunucu yanıtı (ms)',
        'state' => 'Ölçüm durumu', 'error' => 'Hata', 'checked_at' => 'Ölçüm zamanı',
    ];

    public function __construct(private Crawl $crawl) {}

    public function filename(): string
    {
        return Str::slug($this->crawl->loadMissing('project')->project->name ?? 'site').'-hiz-raporu-'.$this->crawl->id.'.xlsx';
    }

    public function download(): BinaryFileResponse
    {
        return response()->download($this->write(), $this->filename(), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /**
     * Writes the workbook to a temporary file and returns its path.
     */
    public function write(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'speed').'.xlsx';
        $bold = (new Style)->setFontBold();
        $timezone = config('screamingrabbit.alerts.timezone');

        $writer = new Writer;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName('Özet');
        $summary = SpeedScan::summary($this->crawl);
        $project = $this->crawl->project;
        foreach ([
            ['Site', $project?->base_url],
            ['Proje', $project?->name],
            ['Tarama', '#'.$this->crawl->id.' · '.($this->crawl->finished_at ?? $this->crawl->created_at)?->timezone($timezone)->format('d.m.Y H:i')],
            ['Ölçülen', $summary['done'].' / '.$summary['total']],
            [],
        ] as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->addRow(Row::fromValues(['Cihaz', 'Ölçülen sayfa', 'Ortalama puan', 'CWV iyi', 'İyileştirilmeli', 'Kötü', 'Puanı 50 altı'], $bold));
        foreach ($summary['strategies'] as $strategy => $row) {
            $writer->addRow(Row::fromValues([SpeedResult::STRATEGIES[$strategy], $row['count'], $row['average'], $row['good'], $row['needs'], $row['poor'], $row['slow']]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['"İyi" sınırları (75. yüzdelik): LCP ≤ 2500 ms, INP ≤ 200 ms, CLS ≤ 0,1. Saha = gerçek Chrome kullanıcıları (28 gün), lab = Lighthouse ölçümü.']));
        $brand = config('screamingrabbit.brand');
        $writer->addRow(Row::fromValues([$brand['product'].' · '.$brand['name'].' · '.$brand['url']]));

        $strategies = $this->crawl->speedResults()->distinct()->pluck('strategy')->all();
        foreach (array_keys(SpeedResult::STRATEGIES) as $strategy) {
            if (! in_array($strategy, $strategies, true)) {
                continue;
            }
            $writer->addNewSheetAndMakeItCurrent()->setName(SpeedResult::STRATEGIES[$strategy]);
            $writer->addRow(Row::fromValues(array_values(self::COLUMNS), $bold));

            $this->crawl->speedResults()->where('strategy', $strategy)->orderBy('id')->chunkById(500, function ($rows) use ($writer, $timezone) {
                foreach ($rows as $result) {
                    $writer->addRow(Row::fromValues(array_map(fn (string $column) => $this->cell($result, $column, $timezone), array_keys(self::COLUMNS))));
                }
            });
        }

        $writer->close();

        return $path;
    }

    private function cell(SpeedResult $result, string $column, string $timezone): mixed
    {
        $value = $result->{$column};
        // A text starting with "=" would become a formula; Google's error messages are not trusted.
        if (is_string($value) && str_starts_with($value, '=')) {
            $value = "'".$value;
        }

        return match ($column) {
            'page_type' => $value === 'home' ? 'Ana sayfa' : (Segments::TYPES[$value] ?? $value),
            'cwv' => SpeedResult::CWV[$value] ?? null,
            'cwv_from_field' => $result->cwv === null ? null : ($value ? 'Gerçek kullanıcılar' : 'Laboratuvar'),
            'state' => SpeedResult::STATES[$value] ?? $value,
            'checked_at' => $value?->timezone($timezone)->format('d.m.Y H:i'),
            default => $value,
        };
    }
}
