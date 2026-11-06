<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Crawls\CrawlActions;
use App\Filament\Resources\Crawls\CrawlColors;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Models\Crawl;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** The project's past crawls, normal and speed scans together, with stop and delete. */
class CrawlsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'crawls';

    protected static ?string $title = 'Tarama geçmişi';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->reorder()->withCount([
                'pages',
                'issues as high_count' => fn ($q) => $q->where('severity', 'high'),
                'issues as medium_count' => fn ($q) => $q->where('severity', 'medium'),
                'issues as low_count' => fn ($q) => $q->where('severity', 'low'),
                'speedResults as speed_total',
                'speedResults as speed_done' => fn ($q) => $q->where('state', 'done'),
            ])->withAvg(['speedResults as speed_avg' => fn ($q) => $q->where('state', 'done')], 'score'))
            ->columns([
                TextColumn::make('type')->label('Tür')->badge()
                    ->state(fn (Crawl $record): string => $record->speed_scan ? 'Hız taraması' : 'SEO taraması')
                    ->color(fn (Crawl $record): string => $record->speed_scan ? 'info' : 'gray')
                    ->description(fn (Crawl $record): string => explode(' (', ProjectForm::MODES[$record->settings['mode'] ?? 'spider'] ?? '')[0]),
                ViewColumn::make('status')->label('Durum')->view('crawls.status-cell'),
                TextColumn::make('health_score')->label('Sağlık')->placeholder('—')->badge()->alignCenter()
                    ->color(fn (?int $state): string => CrawlColors::health($state)),
                TextColumn::make('pages_count')->label('URL')->numeric(thousandsSeparator: '.')->alignEnd(),
                ViewColumn::make('issues')->label('Bulgular')->view('crawls.issues-cell'),
                TextColumn::make('speed')->label('Hız')->placeholder('—')
                    ->state(fn (Crawl $record): ?string => $record->speed_scan && $record->speed_done > 0 ? 'Ort. '.(int) round((float) $record->speed_avg) : null)
                    ->description(fn (Crawl $record): ?string => $record->speed_scan ? number_format((int) $record->speed_done, 0, ',', '.').' / '.number_format((int) $record->speed_total, 0, ',', '.').' ölçüm' : null),
                TextColumn::make('started_at')->label('Tarih')->sortable()->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state?->timezone(config('screamingrabbit.alerts.timezone'))->format('d.m.Y H:i')),
            ])
            ->defaultSort('id', 'desc')
            ->poll(fn (): ?string => $this->getOwnerRecord()->crawls()->whereIn('status', ['pending', 'running'])->exists() ? '10s' : null)
            ->recordUrl(fn (Crawl $record): string => CrawlResource::getUrl('view', ['record' => $record]))
            ->filters([
                SelectFilter::make('speed_scan')->label('Tür')->options(['0' => 'SEO taraması', '1' => 'Hız taraması']),
                SelectFilter::make('status')->label('Durum')->options(Crawl::STATUSES),
            ])
            ->recordActions([
                Action::make('open')->label('Aç')->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (Crawl $record): string => CrawlResource::getUrl('view', ['record' => $record])),
                ActionGroup::make([CrawlActions::cancel(), CrawlActions::delete()])->icon(Heroicon::EllipsisVertical)->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([CrawlActions::bulkDelete()]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedMagnifyingGlass)
            ->emptyStateHeading('Bu projede henüz tarama yok')
            ->emptyStateDescription('Sağ üstteki "Taramayı başlat" ile ilk taramayı yap.');
    }
}
