<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\Project;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who changed what (docs/09 Faz 4). Read-only. The primary admin sees everything; other members see changes on the
 * projects they can see.
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $modelLabel = 'İşlem';

    protected static ?string $pluralModelLabel = 'İşlem günlüğü';

    protected static ?int $navigationSort = 95;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user === null || $user->isPrimary() ? $query : $query->whereIn('project_id', Project::query()->visibleTo($user)->select('projects.id'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'project']))
            ->columns([
                TextColumn::make('created_at')->label('Zaman')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('user.name')->label('Kim')->placeholder('Silinmiş üye'),
                TextColumn::make('action')->label('İşlem')->badge()
                    ->formatStateUsing(fn (string $state): string => ActivityLog::ACTIONS[$state] ?? $state)
                    ->color(fn (string $state): string => ['created' => 'success', 'updated' => 'info', 'deleted' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('label')->label('Ne')->searchable()->wrap()
                    ->description(fn (ActivityLog $record): ?string => $record->changes ? collect($record->changes)
                        ->map(fn (array $change, string $field) => (ActivityLog::FIELDS[$field] ?? $field).': '.self::show($change['old']).' → '.self::show($change['new']))->implode(' · ') : null),
                TextColumn::make('project.name')->label('Proje')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('user')->label('Kim')->relationship('user', 'name'),
                SelectFilter::make('project')->label('Proje')->relationship('project', 'name'),
                SelectFilter::make('action')->label('İşlem')->options(ActivityLog::ACTIONS),
            ]);
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'evet' : 'hayır',
            default => (string) $value,
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListActivityLogs::route('/')];
    }
}
