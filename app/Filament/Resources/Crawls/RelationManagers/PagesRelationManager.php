<?php

namespace App\Filament\Resources\Crawls\RelationManagers;

use App\Filament\Resources\Crawls\CrawlColors;
use App\Models\CrawlPage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PagesRelationManager extends RelationManager
{
    // Load the tab content together with the page instead of after a delay.
    protected static bool $isLazy = false;

    protected static string $relationship = 'pages';

    protected static ?string $title = 'URL listesi';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('url')
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
                TextColumn::make('response_ms')->label('Süre (ms)')->numeric()->sortable()->toggleable(isToggledHiddenByDefault: true),
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
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
