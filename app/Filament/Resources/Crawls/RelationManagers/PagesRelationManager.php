<?php

namespace App\Filament\Resources\Crawls\RelationManagers;

use App\Filament\Resources\Crawls\CrawlColors;
use App\Filament\Resources\Crawls\Support\PageDetail;
use App\Google\GscSync;
use App\Models\CrawlPage;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class PagesRelationManager extends RelationManager
{
    // Load the tab content together with the page instead of after a delay.
    protected static bool $isLazy = false;

    protected static string $relationship = 'pages';

    protected static ?string $title = 'URL listesi';

    public function table(Table $table): Table
    {
        $projectId = $this->getOwnerRecord()->project_id;
        $hasGsc = filled($this->getOwnerRecord()->project?->gsc_property);

        return $table
            ->recordTitleAttribute('url')
            // Search Console totals for the latest 28 days, matched to the crawled URL.
            ->modifyQueryUsing(fn ($query) => $hasGsc
                ? $query->leftJoinSub(GscSync::last28($projectId), 'gsc', 'gsc.page_hash', '=', 'crawl_pages.url_hash')
                    ->leftJoinSub(DB::table('gsc_inspections')->where('project_id', $projectId)->select('page_hash', 'verdict', 'coverage_state'), 'gi', 'gi.page_hash', '=', 'crawl_pages.url_hash')
                    ->select('crawl_pages.*', 'gsc.gsc_clicks', 'gsc.gsc_impressions', 'gsc.gsc_position', 'gi.verdict as gsc_verdict', 'gi.coverage_state as gsc_coverage')
                : $query)
            ->columns([
                TextColumn::make('url')->label('URL')->limit(70)->tooltip(fn (CrawlPage $record): string => $record->url)
                    ->url(fn (CrawlPage $record): string => $record->url, shouldOpenInNewTab: true)->searchable(),
                TextColumn::make('status_code')->label('Durum')->badge()->placeholder('Yanıt yok')
                    ->color(fn (?int $state): string => CrawlColors::httpStatus($state))->sortable(),
                TextColumn::make('title')->label('Title')->limit(50)->searchable(),
                TextColumn::make('h1')->label('H1')->limit(40)->toggleable(),
                TextColumn::make('word_count')->label('Kelime')->numeric()->sortable()->toggleable(),
                TextColumn::make('depth')->label('Derinlik')->sortable()->toggleable(),
                TextColumn::make('inlinks')->label('İç link')->numeric()->sortable()->toggleable(),
                TextColumn::make('ai_score')->label('AI hazırlık')->numeric()->placeholder('—')->sortable()->toggleable()
                    ->color(fn (?int $state): string => $state === null ? 'gray' : ($state >= 80 ? 'success' : ($state >= 50 ? 'warning' : 'danger')))
                    ->tooltip('AI aramalarında (Google AI özetleri, ChatGPT, Perplexity) okunup alıntılanmaya ne kadar hazır (0-100)'),
                TextColumn::make('link_score')->label('Link puanı')->numeric()->placeholder('—')->sortable()->toggleable()
                    ->tooltip('Sitenin iç link yapısına göre sayfanın önemi (1-100, PageRank benzeri)'),
                TextColumn::make('response_ms')->label('Süre (ms)')->numeric()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('schema_types')->label('Şema')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('gsc_clicks')->label('Tıklama (28 g)')->numeric()->placeholder('0')->sortable()->visible($hasGsc),
                TextColumn::make('gsc_impressions')->label('Gösterim (28 g)')->numeric()->placeholder('0')->sortable()->visible($hasGsc),
                TextColumn::make('gsc_ctr')->label('CTR')->visible($hasGsc)->toggleable()
                    ->state(fn (CrawlPage $record): ?string => $record->gsc_impressions > 0 ? '%'.number_format($record->gsc_clicks / $record->gsc_impressions * 100, 1, ',', '.') : null)->placeholder('—'),
                TextColumn::make('gsc_position')->label('Ort. pozisyon')->numeric(decimalPlaces: 1)->placeholder('—')->sortable()->visible($hasGsc)->toggleable(),
                TextColumn::make('gsc_coverage')->label('Google indeksi')->badge()->limit(40)->placeholder('Denetlenmedi')->visible($hasGsc)->toggleable()
                    ->color(fn (CrawlPage $record): string => match ($record->gsc_verdict) {
                        'PASS' => 'success', 'FAIL' => 'danger', 'NEUTRAL' => 'warning', default => 'gray'
                    }),
                IconColumn::make('in_sitemap')->label('Sitemap')->boolean()->toggleable(),
                TextColumn::make('redirect_to')->label('Yönlendirme')->limit(50)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('status_class')
                    ->label('Durum sınıfı')
                    ->options(['2' => '2xx', '3' => '3xx', '4' => '4xx', '5' => '5xx'])
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereBetween('status_code', [(int) $data['value'] * 100, (int) $data['value'] * 100 + 99])
                        : $query),
                Filter::make('in_sitemap')->label('Sitemap\'te olanlar')->query(fn ($query) => $query->where('in_sitemap', true)),
                Filter::make('queued')->label('Sırada bekleyenler')->query(fn ($query) => $query->where('state', 'queued')),
            ])
            ->recordActions([
                Action::make('detail')
                    ->label('Detay')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (CrawlPage $record): string => $record->url)
                    ->modalContent(fn (CrawlPage $record) => view('crawls.page-detail', ['detail' => PageDetail::for($record)]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Kapat'),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
