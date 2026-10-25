<?php

namespace App\Filament\Resources\Monitors\RelationManagers;

use App\Models\Incident;
use App\Models\Monitor;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IncidentsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'incidents';

    protected static ?string $title = 'Kesintiler';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('started_at')->label('Başlangıç')->dateTime('d.m.Y H:i'),
                TextColumn::make('ended_at')->label('Bitiş')->dateTime('d.m.Y H:i')->placeholder('Sürüyor')->badge(fn (Incident $record): bool => $record->ended_at === null)->color(fn (Incident $record): ?string => $record->ended_at === null ? 'danger' : null),
                TextColumn::make('duration')->label('Süre')->state(fn (Incident $record): string => $record->durationForHumans()),
                TextColumn::make('cause')->label('Neden')->formatStateUsing(fn (string $state): string => Monitor::CAUSES[$state] ?? $state),
                TextColumn::make('detail')->label('Ayrıntı')->limit(60)->placeholder('—'),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginated([25, 50]);
    }
}
