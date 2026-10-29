<?php

namespace App\Filament\Resources\Crawls\Pages;

use App\Crawler\CrawlRunner;
use App\Exports\CrawlCsv;
use App\Exports\CrawlSitemap;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Crawl;
use App\Reports\CrawlReport;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ViewCrawl extends ViewRecord
{
    protected static string $resource = CrawlResource::class;

    /** Polled by the progress bar while the crawl runs; stops re-rendering once it has finished. */
    public function refreshProgress(): void
    {
        $this->record->refresh();
    }

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
                ->visible(fn (Crawl $record): bool => ! $record->isActive() && auth()->user()->canDo('crawl', $record->project_id))
                ->action(function (Crawl $record): void {
                    $crawl = CrawlRunner::start($record->project);
                    Notification::make()->title('Yeni tarama başlatıldı')->success()->send();
                    $this->redirect(CrawlResource::getUrl('view', ['record' => $crawl]));
                }),
            Action::make('share')
                ->label('Müşteri raporu')
                ->icon(Heroicon::OutlinedLink)
                ->color('primary')
                ->visible(fn (Crawl $record): bool => $record->status === 'completed' && auth()->user()->canDo('report', $record->project_id))
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
                    $url = route('report.show', $record->share_token);
                    $this->js('navigator.clipboard?.writeText('.json_encode($url).')');
                    Notification::make()
                        ->title('Rapor bağlantısı hazır ve panoya kopyalandı')
                        ->body($url)
                        ->success()
                        ->persistent()
                        ->actions([
                            Action::make('copy')
                                ->label('Tekrar kopyala')
                                ->alpineClickHandler('navigator.clipboard.writeText('.json_encode($url).')'),
                        ])
                        ->send();
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
            ActionGroup::make([
                Action::make('csvPages')
                    ->label('URL listesi (CSV)')
                    ->action(fn (Crawl $record) => (new CrawlCsv($record))->pages()),
                Action::make('csvIssues')
                    ->label('Sorunlar (CSV)')
                    ->action(fn (Crawl $record) => (new CrawlCsv($record))->issues()),
                Action::make('sitemap')
                    ->label('Site haritası (XML)')
                    ->tooltip('İndekslenebilir sayfalar ve görselleriyle; WordPress dışı siteler için veya kontrol amacıyla')
                    ->visible(fn (Crawl $record): bool => $record->status === 'completed')
                    ->action(fn (Crawl $record) => (new CrawlSitemap($record))->download()),
            ])
                ->label('Dışa aktar')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->button()
                ->visible(fn (): bool => ! $this->record->isActive()),
            Action::make('cancel')
                ->label('Taramayı durdur')
                ->icon(Heroicon::OutlinedStop)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Crawl $record): bool => $record->isActive() && auth()->user()->canDo('crawl', $record->project_id))
                ->action(fn (Crawl $record) => $record->update(['status' => 'cancelled', 'finished_at' => now()])),
        ];
    }
}
