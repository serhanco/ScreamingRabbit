<?php

namespace App\Filament\Resources\Crawls\Tables;

use App\Filament\Resources\Crawls\CrawlColors;
use App\Models\Crawl;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CrawlsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('project')->withCount(['pages', 'issues']))
            ->columns([
                TextColumn::make('project.name')->label('Proje')->searchable(),
                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Crawl::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => CrawlColors::status($state))
                    ->description(fn (Crawl $record): ?string => $record->isActive() ? (fn (array $p) => "%{$p['percent']} · {$p['phase']}")($record->progress()) : null),
                TextColumn::make('health_score')->label('Skor')->placeholder('-')->badge()
                    ->color(fn (?int $state): string => CrawlColors::health($state)),
                TextColumn::make('pages_count')->label('URL')->numeric(),
                TextColumn::make('issues_count')->label('Bulgu')->numeric(),
                TextColumn::make('started_at')->label('Başlangıç')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('finished_at')->label('Bitiş')->dateTime('d.m.Y H:i')->placeholder('-'),
            ])
            ->defaultSort('id', 'desc')
            ->poll('15s')
            ->recordActions([
                ViewAction::make()->label('Aç'),
            ]);
    }
}
