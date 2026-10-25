<?php

namespace App\Filament\Resources\Crawls\RelationManagers;

use App\Audit\RuleCatalog;
use App\Filament\Resources\Crawls\CrawlColors;
use App\Models\CrawlIssue;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class IssuesRelationManager extends RelationManager
{
    // Load the tab content together with the page instead of after a delay.
    protected static bool $isLazy = false;

    protected static string $relationship = 'issues';

    protected static ?string $title = 'Bulgular';

    public function table(Table $table): Table
    {
        $catalog = RuleCatalog::all();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('page')
                ->orderByRaw("CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
                ->orderBy('rule')->orderBy('id'))
            ->recordTitleAttribute('rule')
            ->columns([
                TextColumn::make('severity')
                    ->label('Önem')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => RuleCatalog::SEVERITIES[$state] ?? $state)
                    ->color(fn (string $state): string => CrawlColors::severity($state)),
                TextColumn::make('rule')
                    ->label('Sorun')
                    ->formatStateUsing(fn (string $state): string => $catalog[$state]['title'] ?? $state)
                    ->description(fn (CrawlIssue $record): ?string => $catalog[$record->rule]['fix'] ?? null)
                    ->wrap(),
                TextColumn::make('page.url')->label('URL')->limit(70)->tooltip(fn (CrawlIssue $record): ?string => $record->page?->url)
                    ->url(fn (CrawlIssue $record): ?string => $record->page?->url, shouldOpenInNewTab: true)->searchable(),
                TextColumn::make('detail')->label('Ayrıntı')->wrap(),
            ])
            ->groups([
                Group::make('rule')
                    ->label('Sorun türü')
                    ->getTitleFromRecordUsing(fn (CrawlIssue $record): string => $catalog[$record->rule]['title'] ?? $record->rule)
                    ->getDescriptionFromRecordUsing(fn (CrawlIssue $record): ?string => $catalog[$record->rule]['description'] ?? null)
                    ->collapsible(),
            ])
            ->defaultGroup('rule')
            ->filters([
                SelectFilter::make('severity')->label('Önem')->options(RuleCatalog::SEVERITIES),
                SelectFilter::make('rule')->label('Sorun')->options(collect($catalog)->map(fn (array $rule) => $rule['title'])->all())->searchable(),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
