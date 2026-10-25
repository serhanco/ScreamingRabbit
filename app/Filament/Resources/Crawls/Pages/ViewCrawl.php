<?php

namespace App\Filament\Resources\Crawls\Pages;

use App\Filament\Resources\Crawls\CrawlResource;
use App\Crawler\CrawlRunner;
use App\Models\Crawl;
use App\Reports\CrawlReport;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

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
            Action::make('share')
                ->label('Müşteri raporu')
                ->icon(Heroicon::OutlinedLink)
                ->color('primary')
                ->visible(fn (Crawl $record): bool => $record->status === 'completed')
                ->modalHeading('Müşteri raporu')
                ->modalDescription(fn (Crawl $record): string => $record->share_token
                    ? 'Bu bağlantıya sahip herkes raporu görebilir: '.route('report.show', $record->share_token)
                    : 'Paylaşılabilir bir bağlantı oluşturulur; istediğin zaman iptal edebilirsin.')
                ->modalSubmitActionLabel(fn (Crawl $record): string => $record->share_token ? 'Yeni bağlantı oluştur' : 'Bağlantı oluştur')
                ->extraModalFooterActions(fn (Crawl $record): array => $record->share_token ? [
                    Action::make('revoke')
                        ->label('Bağlantıyı iptal et')
                        ->color('danger')
                        ->action(function (Crawl $record): void {
                            $record->update(['share_token' => null, 'shared_at' => null]);
                            Notification::make()->title('Bağlantı iptal edildi')->success()->send();
                        })
                        ->cancelParentActions(),
                ] : [])
                ->action(function (Crawl $record): void {
                    $record->update(['share_token' => Str::random(40), 'shared_at' => now()]);
                    Notification::make()->title('Rapor bağlantısı hazır')->body(route('report.show', $record->share_token))->success()->persistent()->send();
                }),
            Action::make('pdf')
                ->label('PDF indir')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (Crawl $record): bool => $record->status === 'completed')
                ->action(function (Crawl $record) {
                    $report = new CrawlReport($record);

                    return response()->streamDownload(fn () => print ($report->pdf()), $report->filename(), ['Content-Type' => 'application/pdf']);
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
