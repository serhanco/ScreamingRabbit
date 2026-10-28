<?php

namespace App\Filament\Resources\WatchedPages\RelationManagers;

use App\Models\WatchedPage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChangesRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'changes';

    protected static ?string $title = 'Değişiklik geçmişi';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('detected_at')->label('Zaman')->dateTime('d.m.Y H:i'),
                TextColumn::make('field')->label('Ne değişti')->formatStateUsing(fn (string $state): string => WatchedPage::FIELDS[$state] ?? $state)->badge(),
                TextColumn::make('old_value')->label('Önce')->limit(70)->wrap(),
                TextColumn::make('new_value')->label('Sonra')->limit(70)->wrap(),
            ])
            ->defaultSort('detected_at', 'desc')
            ->paginated([25, 50]);
    }
}
