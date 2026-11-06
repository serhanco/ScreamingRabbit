<?php

namespace App\Filament\Resources\Crawls\RelationManagers;

use App\Exports\SpeedXlsx;
use App\Models\Crawl;
use App\Models\SpeedResult;
use App\Performance\PageSpeed;
use App\Performance\SpeedScan;
use App\Segments\Segments;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Every page of a site-wide speed scan, mobile and desktop, with the Excel download. */
class SpeedRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'speedResults';

    protected static ?string $title = 'Hız (PageSpeed)';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Crawl && $ownerRecord->speed_scan;
    }

    public function table(Table $table): Table
    {
        $ms = fn (?int $state): ?string => $state === null ? null : number_format($state / 1000, 1, ',', '.').' sn';
        $rate = fn (string $metric) => function (SpeedResult $record) use ($metric): string {
            $value = $record->{$metric};
            $key = str_replace('field_', '', $metric);
            $limit = PageSpeed::LIMITS[$key] ?? SpeedScan::LAB_LIMITS[$key] ?? null;
            if ($value === null || $limit === null) {
                return 'gray';
            }

            return $value <= $limit['good'] ? 'success' : ($value <= $limit['poor'] ? 'warning' : 'danger');
        };

        return $table
            ->recordTitleAttribute('url')
            ->columns([
                TextColumn::make('url')->label('URL')->limit(60)->tooltip(fn (SpeedResult $record): string => $record->url)
                    ->url(fn (SpeedResult $record): string => 'https://pagespeed.web.dev/analysis?url='.rawurlencode($record->url).'&form_factor='.$record->strategy, shouldOpenInNewTab: true)->searchable(),
                TextColumn::make('strategy')->label('Cihaz')->formatStateUsing(fn (string $state): string => SpeedResult::STRATEGIES[$state] ?? $state),
                TextColumn::make('page_type')->label('Tür')->formatStateUsing(fn (?string $state): string => $state === 'home' ? 'Ana sayfa' : (Segments::TYPES[$state] ?? $state))->placeholder('—')->toggleable(),
                TextColumn::make('score')->label('Puan')->sortable()->placeholder(fn (SpeedResult $record): string => SpeedResult::STATES[$record->state] ?? '—')
                    ->color(fn (?int $state): string => $state === null ? 'gray' : ($state >= 90 ? 'success' : ($state >= PageSpeed::POOR_SCORE ? 'warning' : 'danger')))
                    ->tooltip(fn (SpeedResult $record): ?string => $record->error),
                TextColumn::make('cwv')->label('Core Web Vitals')->badge()->placeholder('—')
                    ->formatStateUsing(fn (string $state, SpeedResult $record): string => (SpeedResult::CWV[$state] ?? $state).($record->cwv_from_field ? '' : ' (lab)'))
                    ->color(fn (string $state): string => ['good' => 'success', 'needs' => 'warning', 'poor' => 'danger'][$state] ?? 'gray')
                    ->tooltip(fn (SpeedResult $record): ?string => $record->cwv === null ? null : ($record->cwv_from_field ? 'Gerçek kullanıcı verisi (28 gün)' : 'Gerçek kullanıcı verisi yok; laboratuvar ölçümünden tahmin')),
                TextColumn::make('field_lcp')->label('LCP (saha)')->sortable()->placeholder('—')->formatStateUsing(fn (?int $state) => $ms($state))->color($rate('field_lcp')),
                TextColumn::make('field_inp')->label('INP (saha)')->sortable()->placeholder('—')->suffix(' ms')->color($rate('field_inp')),
                TextColumn::make('field_cls')->label('CLS (saha)')->sortable()->placeholder('—')->color($rate('field_cls')),
                TextColumn::make('lcp')->label('LCP (lab)')->sortable()->placeholder('—')->formatStateUsing(fn (?int $state) => $ms($state))->color($rate('lcp')),
                TextColumn::make('tbt')->label('TBT')->sortable()->placeholder('—')->suffix(' ms')->color($rate('tbt')),
                TextColumn::make('cls')->label('CLS (lab)')->sortable()->placeholder('—')->color($rate('cls')),
                TextColumn::make('fcp')->label('FCP')->sortable()->placeholder('—')->formatStateUsing(fn (?int $state) => $ms($state))->toggleable(),
                TextColumn::make('si')->label('Speed Index')->sortable()->placeholder('—')->formatStateUsing(fn (?int $state) => $ms($state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ttfb')->label('Sunucu yanıtı')->sortable()->placeholder('—')->suffix(' ms')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('strategy')->label('Cihaz')->options(SpeedResult::STRATEGIES),
                SelectFilter::make('cwv')->label('Core Web Vitals')->options(SpeedResult::CWV),
                SelectFilter::make('state')->label('Ölçüm durumu')->options(SpeedResult::STATES),
                Filter::make('slow')->label('Puanı 50 altı')->query(fn ($query) => $query->where('score', '<', PageSpeed::POOR_SCORE)),
            ])
            ->recordActions([
                Action::make('remeasure')->label('Yeniden ölç')->icon(Heroicon::OutlinedArrowPath)->iconButton()->tooltip('Bu sayfayı yeniden ölç')
                    ->visible(fn (SpeedResult $record): bool => in_array($record->state, ['done', 'failed', 'cancelled'], true) && (auth()->user()?->canDo('crawl', $this->getOwnerRecord()->project_id) ?? false))
                    ->action(function (SpeedResult $record): void {
                        SpeedScan::requeueOne($record);
                        Notification::make()->title('Sayfa yeniden ölçüm sırasına alındı')->body('Bir iki dakika içinde sonuç gelir.')->success()->send();
                    }),
            ])
            ->headerActions([
                Action::make('xlsx')->label('Excel indir')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn (): bool => $this->getOwnerRecord()->speedResults()->where('state', 'done')->exists())
                    ->action(fn () => (new SpeedXlsx($this->getOwnerRecord()))->download()),
            ])
            ->emptyStateHeading('Ölçümler tarama bitince başlar')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
