<?php

namespace App\Filament\Resources\Crawls\Tables;

use App\Filament\Resources\Crawls\CrawlActions;
use App\Filament\Resources\Crawls\CrawlColors;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Crawl;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CrawlsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('project')->withCount([
                'pages',
                'issues as high_count' => fn ($q) => $q->where('severity', 'high'),
                'issues as medium_count' => fn ($q) => $q->where('severity', 'medium'),
                'issues as low_count' => fn ($q) => $q->where('severity', 'low'),
            ]))
            ->columns([
                TextColumn::make('project.name')->label('Proje')->searchable()->weight('semibold')
                    ->description(fn (Crawl $record): string => preg_replace('#^https?://(www\.)?#', '', rtrim((string) $record->project?->base_url, '/'))),
                ViewColumn::make('status')->label('Durum')->view('crawls.status-cell'),
                TextColumn::make('health_score')->label('Sağlık')->placeholder('—')->badge()->alignCenter()
                    ->color(fn (?int $state): string => CrawlColors::health($state)),
                TextColumn::make('pages_count')->label('URL')->numeric(thousandsSeparator: '.')->alignEnd(),
                ViewColumn::make('issues')->label('Bulgular')->view('crawls.issues-cell'),
                TextColumn::make('started_at')->label('Tarih')->sortable()->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state?->timezone(config('screamingrabbit.alerts.timezone'))->format('d.m.Y H:i'))
                    ->description(fn (Crawl $record): ?string => self::duration($record)),
            ])
            ->defaultSort('id', 'desc')
            ->poll(fn (): ?string => Crawl::query()->whereIn('status', ['pending', 'running'])->exists() ? '10s' : null)
            ->recordUrl(fn (Crawl $record): string => CrawlResource::getUrl('view', ['record' => $record]))
            ->filters([
                SelectFilter::make('project')->label('Proje')->relationship('project', 'name')->searchable()->preload(),
                SelectFilter::make('status')->label('Durum')->options(Crawl::STATUSES),
            ])
            ->recordActions([
                ViewAction::make()->label('Aç')->icon(Heroicon::OutlinedArrowRight),
                ActionGroup::make([CrawlActions::cancel(), CrawlActions::delete()])->icon(Heroicon::EllipsisVertical)->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([CrawlActions::bulkDelete()]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedMagnifyingGlass)
            ->emptyStateHeading('Henüz tarama yok')
            ->emptyStateDescription('Sağ üstteki "Yeni tarama" ile bir projenin sitesini taramaya başla.');
    }

    /** "14 dk sürdü", or how long a running crawl has been going. */
    private static function duration(Crawl $record): ?string
    {
        if ($record->started_at === null) {
            return null;
        }
        $minutes = (int) $record->started_at->diffInMinutes($record->finished_at ?? now());
        $text = $minutes < 1 ? '1 dk\'dan kısa' : ($minutes >= 60 ? intdiv($minutes, 60).' sa '.($minutes % 60).' dk' : $minutes.' dk');

        return $record->isActive() ? $text.' sürüyor' : $text.' sürdü';
    }
}
