<?php

namespace App\Filament\Pages;

use App\Google\GoogleClient;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Connects one Google account (the one with access to the clients' Search Console properties).
 *
 * @property-read Schema $form
 */
class GoogleSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Google bağlantısı';

    protected static ?string $title = 'Google bağlantısı (Search Console)';

    protected static ?string $slug = 'google';

    protected static ?int $navigationSort = 91;

    /** The Google account and its keys are shared by the whole panel, so only admins manage them. */
    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['google_client_id' => Setting::get('google_client_id'), 'google_client_secret' => Setting::get('google_client_secret')]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Durum')
                    ->schema([
                        Text::make(fn (): string => GoogleClient::connected()
                            ? 'Bağlı hesap: '.Setting::get('google_email').'. Proje ayarlarından her projeye Search Console mülkünü seç.'
                            : 'Henüz bağlı değil. Önce aşağıya OAuth istemci bilgilerini kaydet, sonra "Google hesabını bağla" düğmesine bas.'),
                    ]),
                Section::make('Kurulum rehberi')
                    ->description('Google Cloud\'da OAuth istemcisi oluşturma adımları (bir kez, yaklaşık 10 dakika).')
                    ->collapsible()
                    ->collapsed(fn (): bool => GoogleClient::connected())
                    ->schema([View::make('google.setup-guide')]),
                Section::make('OAuth istemcisi')
                    ->description('Google Cloud Console\'da bir kez oluşturulur. "Yetkili yönlendirme URI\'si" olarak şunu ekle: '.GoogleClient::redirectUri())
                    ->columns(2)
                    ->schema([
                        TextInput::make('google_client_id')->label('İstemci kimliği (Client ID)'),
                        TextInput::make('google_client_secret')->label('İstemci gizli anahtarı (Client secret)')->password()->revealable(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Kaydet')->submit('save'),
                        Action::make('connect')
                            ->label(fn (): string => GoogleClient::connected() ? 'Başka hesapla yeniden bağla' : 'Google hesabını bağla')
                            ->color('gray')
                            ->icon(Heroicon::OutlinedLink)
                            ->visible(fn (): bool => GoogleClient::configured())
                            ->url(fn (): string => route('filament.admin.google.connect')),
                        Action::make('disconnect')
                            ->label('Bağlantıyı kes')
                            ->color('danger')
                            ->requiresConfirmation()
                            ->visible(fn (): bool => GoogleClient::connected())
                            ->action(function (): void {
                                GoogleClient::disconnect();
                                Notification::make()->title('Google bağlantısı kesildi')->success()->send();
                            }),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        foreach ($this->form->getState() as $key => $value) {
            Setting::put($key, $value);
        }

        Notification::make()->title('Kaydedildi')->success()->send();
    }
}
