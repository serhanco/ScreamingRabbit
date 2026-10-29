<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\WpChange;
use App\Models\WpConnection;
use App\WordPress\Changes;
use App\WordPress\Connector;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
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
            Action::make('propose')
                ->label('Değişiklik öner')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn (): bool => $this->canManage() && ($this->connection()?->isConnected() ?? false))
                ->modalDescription('Sayfanın SEO başlığı, meta açıklaması, noindex ayarı, bir görselin alt metni veya 301 yönlendirme. Öneri önce kaydedilir; siteye ancak listeden onaylanınca yazılır.')
                ->schema([
                    TextInput::make('url')->label(fn (Get $get): string => match ($get('field')) {
                        'alt' => 'Görselin adresi (…/wp-content/uploads/…)',
                        'redirect' => 'Yönlendirilecek eski adres',
                        default => 'Sayfa adresi',
                    })->url()->required()->maxLength(2048)->placeholder($this->record->base_url),
                    Select::make('field')->label('Ne değişsin?')->options(WpChange::FIELDS)->required()->live(),
                    Textarea::make('after')->label('Yeni değer')->rows(3)->maxLength(1000)
                        ->helperText(fn (Get $get): string => $get('field') === 'alt'
                            ? 'Medya kütüphanesindeki alt metin değişir: öne çıkan görsellerde ve yeni eklemelerde kullanılır. Yazıların içine daha önce eklenmiş görsellerin alt metni yazının kendisinde durur.'
                            : 'Boş bırakılırsa SEO eklentisinin varsayılanı kullanılır. Yoast/Rank Math değişkenleri (%%title%% vb.) kullanılabilir.')
                        ->visible(fn (Get $get): bool => in_array($get('field'), ['title', 'description', 'alt'], true)),
                    TextInput::make('target')->label('Yeni adres (301)')->url()->maxLength(2048)
                        ->helperText('Aynı sitede olmalı. Boş bırakılırsa bu adresteki ScreamingRabbit yönlendirmesi kaldırılır.')
                        ->visible(fn (Get $get): bool => $get('field') === 'redirect'),
                    Select::make('robots')->label('Yeni değer')->options(WpChange::NOINDEX)->selectablePlaceholder(false)->default('')
                        ->visible(fn (Get $get): bool => $get('field') === 'noindex'),
                ])
                ->action(function (array $data, Action $action): void {
                    $result = Changes::propose($this->record, $data['url'], $data['field'], match ($data['field']) {
                        'noindex' => $data['robots'] ?? '',
                        'redirect' => $data['target'] ?? '',
                        default => $data['after'] ?? '',
                    }, auth()->user());
                    if (is_string($result)) {
                        Notification::make()->title('Öneri kaydedilemedi')->body($result)->danger()->send();
                        $action->halt();
                    }
                    Notification::make()->title('Öneri kaydedildi')->body('Aşağıdaki listeden onaylayınca siteye yazılır.')->success()->send();
                }),
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

    public function applyChangeAction(): Action
    {
        return Action::make('applyChange')
            ->label('Onayla ve uygula')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Değişiklik siteye yazılsın mı?')
            ->modalDescription(fn (array $arguments): string => ($change = $this->change($arguments)) ? WpChange::FIELDS[$change->field].': "'.$change->show($change->before).'" → "'.$change->show($change->after).'"' : '')
            ->visible(fn (): bool => $this->canManage())
            ->action(function (array $arguments): void {
                $change = $this->change($arguments);
                if ($change === null) {
                    return;
                }
                $change = Changes::apply($change, auth()->user());
                Notification::make()->title($change->status === 'applied' ? 'Siteye yazıldı' : 'Uygulanamadı')->body($change->error)
                    ->{$change->status === 'applied' ? 'success' : 'danger'}()->send();
            });
    }

    public function rejectChangeAction(): Action
    {
        return Action::make('rejectChange')
            ->label('Reddet')
            ->size('sm')
            ->color('gray')
            ->visible(fn (): bool => $this->canManage())
            ->action(fn (array $arguments) => $this->change($arguments)?->update(['status' => 'rejected']));
    }

    public function revertChangeAction(): Action
    {
        return Action::make('revertChange')
            ->label('Geri al')
            ->size('sm')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Sayfadaki değer önceki haline döner (o arada sitede değiştiyse çakışma olarak durur).')
            ->visible(fn (): bool => $this->canManage())
            ->action(function (array $arguments): void {
                $change = $this->change($arguments);
                if ($change === null || $change->status !== 'applied') {
                    return;
                }
                $reverse = Changes::revert($change, auth()->user());
                Notification::make()->title($reverse->status === 'applied' ? 'Geri alındı' : 'Geri alınamadı')->body($reverse->error)
                    ->{$reverse->status === 'applied' ? 'success' : 'danger'}()->send();
            });
    }

    /** @param  array<string, mixed>  $arguments */
    private function change(array $arguments): ?WpChange
    {
        return WpChange::where('project_id', $this->record->id)->find($arguments['change'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'connection' => $this->connection(), 'project' => $this->record, 'canManage' => $this->canManage(),
            'changes' => WpChange::where('project_id', $this->record->id)->with(['proposer', 'approver'])->latest('id')->limit(30)->get(),
        ];
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
