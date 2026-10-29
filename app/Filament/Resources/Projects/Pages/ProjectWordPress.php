<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\WpConnection;
use App\WordPress\Connector;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The project's link to the ScreamingRabbit Connector plugin: pairing, and what the plugin reports from inside
 * WordPress (versions, plugins, themes, settings) that an outside crawl cannot see.
 */
class ProjectWordPress extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'projects.wordpress';

    /** The pairing code just made, shown once on the page. */
    public ?string $code = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'WordPress · '.$this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('code')
                ->label(fn (): string => $this->connection()?->isConnected() ? 'Yeniden bağla' : 'Bağlantı kodu al')
                ->icon(Heroicon::OutlinedLink)
                ->visible(fn (): bool => $this->canManage())
                ->requiresConfirmation(fn (): bool => $this->connection()?->isConnected() ?? false)
                ->modalDescription('Yeni kod eklentiye yapıştırılınca mevcut bağlantının anahtarı değişir.')
                ->action(function (): void {
                    $this->code = Connector::newCode($this->record);
                }),
            Action::make('sync')
                ->label('Şimdi yenile')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => $this->canManage() && ($this->connection()?->isConnected() ?? false))
                ->action(function (): void {
                    $ok = Connector::sync($this->connection());
                    Notification::make()->title($ok ? 'Bilgiler güncellendi' : 'Güncellenemedi')->body($ok ? null : $this->connection()->sync_error)
                        ->{$ok ? 'success' : 'danger'}()->send();
                }),
            Action::make('disconnect')
                ->label('Bağlantıyı kes')
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible(fn (): bool => $this->canManage() && $this->connection() !== null)
                ->requiresConfirmation()
                ->modalDescription('Anahtar silinir; eklenti artık bu hesaba bilgi veremez. Eklentiyi sitede ayrıca silebilirsiniz.')
                ->action(function (): void {
                    Connector::disconnect($this->record);
                    $this->code = null;
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['connection' => $this->connection(), 'project' => $this->record, 'canManage' => $this->canManage()];
    }

    private function connection(): ?WpConnection
    {
        return $this->record->wpConnection()->first();
    }

    private function canManage(): bool
    {
        return auth()->user()?->canDo('settings', $this->record->id) ?? false;
    }
}
