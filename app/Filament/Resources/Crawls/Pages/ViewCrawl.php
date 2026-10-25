<?php

namespace App\Filament\Resources\Crawls\Pages;

use App\Filament\Resources\Crawls\CrawlResource;
use App\Crawler\CrawlRunner;
use App\Models\Crawl;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCrawl extends ViewRecord
{
    protected static string $resource = CrawlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A finished crawl no longer changes, so refreshing only makes sense while it is queued or running.
            Action::make('refresh')
                ->label('Yenile')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (Crawl $record): bool => $record->isActive())
                ->action(fn () => $this->record->refresh()),
            Action::make('recrawl')
                ->label('Yeniden tara')
                ->icon(Heroicon::OutlinedPlay)
                ->requiresConfirmation()
                ->modalDescription('Aynı proje için yeni bir tarama başlatılır; bu sonuçlar silinmez.')
                ->visible(fn (Crawl $record): bool => ! $record->isActive())
                ->action(function (Crawl $record): void {
                    $crawl = CrawlRunner::start($record->project);
                    Notification::make()->title('Yeni tarama başlatıldı')->success()->send();
                    $this->redirect(CrawlResource::getUrl('view', ['record' => $crawl]));
                }),
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
