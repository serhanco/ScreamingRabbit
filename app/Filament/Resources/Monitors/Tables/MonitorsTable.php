<?php

namespace App\Filament\Resources\Monitors\Tables;

use App\Models\Monitor;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MonitorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Monitor::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'up' => 'success',
                        'down' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('project.name')->label('Proje')->searchable()->sortable(),
                TextColumn::make('url')->label('Adres')->limit(50)->url(fn (Monitor $record): string => $record->url, shouldOpenInNewTab: true),
                TextColumn::make('uptime')
                    ->label('Uptime (24 sa)')
                    ->state(fn (Monitor $record): ?float => $record->uptime(24))
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : '%'.number_format($state, 2, ',', '.'))
                    ->placeholder('—'),
                ViewColumn::make('uptime_bar')->label('Son 30 gün')->view('monitors.uptime-bar-column'),
                TextColumn::make('last_response_ms')->label('Yanıt')->suffix(' ms')->placeholder('—')->sortable(),
                TextColumn::make('ssl_expires_at')
                    ->label('SSL bitişi')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->color(fn (Monitor $record): ?string => $record->ssl_expires_at?->lt(now()->addDays(14)) ? 'danger' : null),
                TextColumn::make('last_checked_at')->label('Son kontrol')->since()->placeholder('Henüz yok'),
                ToggleColumn::make('is_active')->label('Etkin'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Durum')->options(Monitor::STATUSES),
            ])
            ->defaultSort('status')
            ->poll('60s')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
