<?php

namespace App\Filament\Resources\AlertEvents;

use App\Filament\Concerns\ScopedToUserProjects;
use App\Filament\Resources\AlertEvents\Pages\ListAlertEvents;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Monitoring\Alerts;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Notification center (docs/08 §3): every alert of every kind in one list, with what was sent to the current user.
 */
class AlertEventResource extends Resource
{
    use ScopedToUserProjects;

    protected static ?string $model = AlertEvent::class;

    protected static ?string $slug = 'bildirimler';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $modelLabel = 'Bildirim';

    protected static ?string $pluralModelLabel = 'Bildirimler';

    protected static ?int $navigationSort = 89;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('severity', 'critical')->where('created_at', '>=', now()->subDay())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Zaman')->dateTime('d.m.Y H:i', config('screamingrabbit.alerts.timezone'))->sortable(),
                TextColumn::make('severity')->label('Önem')->badge()
                    ->formatStateUsing(fn (string $state): string => AlertEvent::SEVERITIES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('kind')->label('Tür')->formatStateUsing(fn (string $state): string => Alerts::LABELS[$state] ?? $state),
                TextColumn::make('project.name')->label('Proje')->placeholder('—')->searchable(),
                TextColumn::make('title')->label('Başlık')->limit(70)->searchable()->wrap(),
                TextColumn::make('my_delivery')->label('Bana')
                    ->state(fn (AlertEvent $record): ?string => AlertDelivery::where('alert_event_id', $record->id)->where('user_id', auth()->id())->value('status'))
                    ->formatStateUsing(fn (?string $state): string => AlertDelivery::STATUSES[$state] ?? '—')
                    ->placeholder('—')->color('gray'),
            ])
            ->filters([
                SelectFilter::make('severity')->label('Önem')->options(AlertEvent::SEVERITIES),
                SelectFilter::make('kind')->label('Tür')->options(Alerts::LABELS),
                SelectFilter::make('project')->label('Proje')->relationship('project', 'name', fn ($query) => $query->visibleTo(auth()->user())),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('60s')
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title')->label('Başlık')->columnSpanFull(),
            TextEntry::make('body')->label('Ayrıntı')->columnSpanFull()->formatStateUsing(fn (string $state) => nl2br(e($state)))->html(),
            TextEntry::make('created_at')->label('Zaman')->dateTime('d.m.Y H:i', config('screamingrabbit.alerts.timezone')),
            TextEntry::make('project.name')->label('Proje')->placeholder('—'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAlertEvents::route('/')];
    }
}
