<?php

namespace App\Filament\Resources\Crawls;

use App\Crawler\CrawlMaintenance;
use App\Models\Crawl;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/** Stopping and deleting crawls, shared by the crawl list and the crawl page. */
class CrawlActions
{
    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label('Durdur')
            ->icon(Heroicon::OutlinedStop)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Tarama durdurulsun mu?')
            ->modalDescription('O ana kadar taranan sayfalar kalır; tarama "Durduruldu" olarak işaretlenir. İstediğin zaman yeniden başlatabilirsin.')
            ->modalSubmitActionLabel('Durdur')
            ->visible(fn (Crawl $record): bool => $record->isActive() && (auth()->user()?->canDo('crawl', $record->project_id) ?? false))
            ->action(function (Crawl $record): void {
                $record->update(['status' => 'cancelled', 'finished_at' => now(), 'session_cookie' => null]);
                Notification::make()->title('Tarama durduruldu')->success()->send();
            });
    }

    public static function delete(?string $redirectTo = null): Action
    {
        return Action::make('delete')
            ->label('Sil')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Tarama silinsin mi?')
            ->modalDescription('Bu taramanın tüm sayfaları ve bulguları kalıcı olarak silinir. Sağlık skoru geçmişi ve onaylanmış müşteri raporları etkilenmez.')
            ->modalSubmitActionLabel('Sil')
            ->visible(fn (Crawl $record): bool => ! $record->isActive() && (auth()->user()?->canDo('crawl', $record->project_id) ?? false))
            ->action(function (Crawl $record, $livewire) use ($redirectTo): void {
                CrawlMaintenance::delete($record->id);
                Notification::make()->title('Tarama silindi')->success()->send();
                if ($redirectTo !== null) {
                    $livewire->redirect($redirectTo);
                }
            });
    }

    public static function bulkDelete(): BulkAction
    {
        return BulkAction::make('delete')
            ->label('Seçilenleri sil')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Seçilen taramalar silinsin mi?')
            ->modalDescription('Seçilen taramaların sayfaları ve bulguları kalıcı olarak silinir. Süren taramalar ve yetkin olmayan projelerin taramaları atlanır.')
            ->modalSubmitActionLabel('Sil')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $deleted = 0;
                foreach ($records as $record) {
                    if (! $record->isActive() && auth()->user()?->canDo('crawl', $record->project_id)) {
                        CrawlMaintenance::delete($record->id);
                        $deleted++;
                    }
                }
                Notification::make()->title("{$deleted} tarama silindi")->success()->send();
            });
    }
}
