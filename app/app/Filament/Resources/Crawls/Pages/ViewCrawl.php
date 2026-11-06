<?php

namespace App\Filament\Resources\Crawls\Pages;

use App\Crawler\CrawlRunner;
use App\Exports\CrawlCsv;
use App\Exports\CrawlSitemap;
use App\Exports\SpeedXlsx;
use App\Filament\Pages\SpeedScans;
use App\Filament\Resources\Crawls\CrawlActions;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Crawl;
use App\Performance\SpeedScan;
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
                ->modalDescription(fn (Crawl $record): string => ($record->speed_scan
                    ? 'Aynı adres ve ayarlarla yeni bir hız taraması başlatılır. '.SpeedScans::SPEED_EXPLAINER
                    : 'Aynı proje için yeni bir normal tarama başlatılır. '.SpeedScans::NORMAL_EXPLAINER).' Bu sonuçlar silinmez.')
                ->visible(fn (Crawl $record): bool => ! $record->isActive() && auth()->user()->canDo('crawl', $record->project_id))
                ->action(function (Crawl $record): void {
                    $crawl = $record->speed_scan
                        ? CrawlRunner::start($record->project, SpeedScans::restartSettings($record), speedScan: true)
                        : CrawlRunner::start($record->project);
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
            $this->speedActions(),
            ActionGroup::make([
                Action::make('csvPages')
                    ->label('URL listesi (CSV)')
                    ->action(fn (Crawl $record) => (new CrawlCsv($record))->pages()),
                Action::make('csvIssues')
                    ->label('Sorunlar (CSV)')
                    ->action(fn (Crawl $record) => (new CrawlCsv($record))->issues()),
                Action::make('speedXlsx')
                    ->label('Hız raporu (Excel)')
                    ->visible(fn (Crawl $record): bool => $record->speed_scan && $record->speedResults()->where('state', 'done')->exists())
                    ->action(fn (Crawl $record) => (new SpeedXlsx($record))->download()),
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
            CrawlActions::cancel()->label('Taramayı durdur'),
            CrawlActions::delete(CrawlResource::getUrl('index'))->label('Taramayı sil')->color('gray')->iconButton()->tooltip('Taramayı sil'),
        ];
    }

    /** Pause, resume, stop and re-measure a site-wide speed scan once its crawl has finished. */
    private function speedActions(): ActionGroup
    {
        $pending = fn (Crawl $record): bool => $record->speedResults()->whereIn('state', ['pending', 'running'])->exists();
        $done = fn (Crawl $record, $notification) => Notification::make()->title($notification)->success()->send();

        return ActionGroup::make([
            Action::make('speedPause')->label('Duraklat')->icon(Heroicon::OutlinedPause)
                ->visible(fn (Crawl $record): bool => ! $record->speed_paused && $pending($record))
                ->action(function (Crawl $record) use ($done) {
                    SpeedScan::pause($record);
                    $done($record, 'Ölçüm duraklatıldı');
                }),
            Action::make('speedResume')->label('Devam et')->icon(Heroicon::OutlinedPlay)
                ->visible(fn (Crawl $record): bool => $record->speed_paused && $pending($record))
                ->action(function (Crawl $record) use ($done) {
                    SpeedScan::resume($record);
                    $done($record, 'Ölçüm devam ediyor');
                }),
            Action::make('speedStop')->label('Durdur')->icon(Heroicon::OutlinedStop)->color('danger')
                ->requiresConfirmation()->modalHeading('Ölçüm durdurulsun mu?')
                ->modalDescription('Ölçülen sayfalar kalır; kalanlar ölçülmez. Sonra "Kalanları yeniden ölç" ile tamamlayabilirsiniz.')
                ->visible($pending)
                ->action(function (Crawl $record) use ($done) {
                    SpeedScan::stop($record);
                    $done($record, 'Ölçüm durduruldu');
                }),
            Action::make('speedRetry')->label('Ölçülemeyenleri ve kalanları yeniden ölç')->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (Crawl $record): bool => $record->speedResults()->whereIn('state', ['failed', 'cancelled'])->exists())
                ->action(function (Crawl $record) use ($done) {
                    $done($record, SpeedScan::requeue($record).' sayfa yeniden sıraya alındı');
                }),
            Action::make('speedRestart')->label('Tüm sayfaları baştan ölç')->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                ->requiresConfirmation()->modalHeading('Tüm sayfalar yeniden ölçülsün mü?')
                ->modalDescription('Site yeniden taranmaz; aynı URL listesi yeniden ölçülür (örneğin bir hız düzeltmesinden sonra). Eski değerler yenisi gelene kadar görünür. Yeni sayfaları da almak için yeni bir hız taraması başlatın.')
                ->visible(fn (Crawl $record): bool => ! $pending($record))
                ->action(function (Crawl $record) use ($done) {
                    $done($record, SpeedScan::requeue($record, all: true).' ölçüm yeniden sıraya alındı');
                }),
        ])
            ->label('Hız ölçümü')
            ->icon(Heroicon::OutlinedBolt)
            ->color('gray')
            ->button()
            ->visible(fn (): bool => $this->record->speed_scan && $this->record->status === 'completed' && $this->record->speedResults()->exists()
                && (auth()->user()?->canDo('crawl', $this->record->project_id) ?? false));
    }

    public function getTitle(): string
    {
        return $this->record->project?->name ?? 'Tarama';
    }

    public function getSubheading(): ?string
    {
        $date = ($this->record->started_at ?? $this->record->created_at)?->timezone(config('screamingrabbit.alerts.timezone'))->format('d.m.Y H:i');

        return 'Tarama #'.$this->record->id.($date ? ' · '.$date : '');
    }

    public function getBreadcrumb(): string
    {
        return 'Tarama #'.$this->record->id;
    }
}
