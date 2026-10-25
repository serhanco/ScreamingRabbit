<?php

namespace App\Filament\Resources\Projects\Actions;

use App\Crawler\CrawlRunner;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class StartCrawlAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'startCrawl';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Taramayı başlat')
            ->icon(Heroicon::OutlinedPlay)
            ->requiresConfirmation()
            ->modalHeading('Tarama başlatılsın mı?')
            ->modalDescription('Site, proje ayarlarındaki sınırlara uyularak taranır. Sonuçlar dakikalar içinde dolmaya başlar.')
            ->action(function (Project $record, $livewire): void {
                $active = $record->crawls()->whereIn('status', ['pending', 'running'])->first();

                if ($active) {
                    Notification::make()->title('Bu proje için bir tarama zaten sürüyor')->warning()->send();
                    $livewire->redirect(CrawlResource::getUrl('view', ['record' => $active]));

                    return;
                }

                $crawl = CrawlRunner::start($record);

                Notification::make()->title('Tarama başlatıldı')->success()->send();
                $livewire->redirect(CrawlResource::getUrl('view', ['record' => $crawl]));
            });
    }
}
