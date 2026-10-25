<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Filament\Resources\Projects\Actions\StartCrawlAction;
use App\Models\Project;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Proje')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Müşteri')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('base_url')
                    ->label('Site')
                    ->searchable()
                    ->url(fn (Project $record): string => $record->base_url, shouldOpenInNewTab: true),
                TextColumn::make('platform')
                    ->label('Platform')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Project::PLATFORMS[$state] ?? $state),
            ])
            ->filters([
                SelectFilter::make('client_id')
                    ->label('Müşteri')
                    ->relationship('client', 'name'),
            ])
            ->defaultSort('name')
            ->recordActions([
                StartCrawlAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
