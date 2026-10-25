<?php

namespace App\Filament\Resources\Crawls\Pages;

use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Crawl;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCrawl extends ViewRecord
{
    protected static string $resource = CrawlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Yenile')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->record->refresh()),
            Action::make('cancel')
                ->label('Taramayı durdur')
                ->icon(Heroicon::OutlinedStop)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Crawl $record): bool => $record->isActive())
                ->action(fn (Crawl $record) => $record->update(['status' => 'cancelled', 'finished_at' => now()])),
        ];
    }
}
