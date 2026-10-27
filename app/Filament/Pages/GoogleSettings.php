<?php

namespace App\Filament\Pages;

use App\Google\GoogleClient;
use App\Google\UrlInspector;
use App\Models\Project;
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
 * Each admin connects their own Google account here; the primary admin also keeps the shared OAuth client.
 * The page only ever shows the signed-in user's own connection.
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

    /** Admins (each for their own account); invited members read data through the project owner's connection. */
    public static function canAccess(): bool
    {
        return GoogleClient::canConnect(auth()->user()) || GoogleClient::canManageClient(auth()->user());
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
                Section::make('Senin Google hesabın')
                    ->schema([
                        Text::make(fn (): string => $this->status()),
                    ]),
                Section::make('Kurulum rehberi')
                    ->description(fn (): string => GoogleClient::canManageClient(auth()->user()) ? 'Bir kereye mahsus, yaklaşık 10 dakika.' : 'Tek adım: kendi Google hesabını bağla.')
                    ->collapsible()
                    ->collapsed(fn (): bool => GoogleClient::connected(auth()->user()))
                    ->schema([View::make('google.setup-guide')]),
                Section::make('OAuth istemcisi')
                    ->visible(fn (): bool => GoogleClient::canManageClient(auth()->user()))
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
                        Action::make('save')->label('Kaydet')->submit('save')
                            ->visible(fn (): bool => GoogleClient::canManageClient(auth()->user())),
                        Action::make('connect')
                            ->label(fn (): string => GoogleClient::connected(auth()->user()) ? 'Başka hesapla yeniden bağla' : 'Google hesabını bağla')
                            ->color('gray')
                            ->icon(Heroicon::OutlinedLink)
                            ->visible(fn (): bool => GoogleClient::canConnect(auth()->user()))
                            ->url(fn (): string => route('filament.admin.google.connect')),
                        Action::make('disconnect')
                            ->label('Bağlantıyı kes')
                            ->color('danger')
                            ->requiresConfirmation()
                            ->modalDescription('Senin hesabınla bağlanan projelerin Search Console verileri bundan sonra güncellenmez (eski veriler kalır).')
                            ->visible(fn (): bool => GoogleClient::connected(auth()->user()))
                            ->action(function (): void {
                                GoogleClient::disconnect(GoogleClient::connectionOf(auth()->user()));
                                Notification::make()->title('Google bağlantısı kesildi')->success()->send();
                            }),
                    ]),
                ]),
        ]);
    }

    private function status(): string
    {
        $connection = GoogleClient::connectionOf(auth()->user());
        if ($connection === null) {
            return GoogleClient::configured()
                ? 'Henüz bağlamadın. "Google hesabını bağla" düğmesine bas ve müşteri sitelerini Search Console\'da gören hesabını seç.'
                : 'Henüz bağlı değil. Önce aşağıya OAuth istemci bilgilerini kaydet, sonra "Google hesabını bağla" düğmesine bas.';
        }

        $projects = Project::query()->where('gsc_user_id', auth()->id())->count();

        return "Bağlı hesap: {$connection->email}. Bu hesapla {$projects} proje okunuyor. Bugün {$connection->callsToday()} istek, {$connection->inspectionsToday()}/".UrlInspector::PER_CONNECTION_DAY.' URL denetimi yapıldı. Proje ayarlarından her projeye Search Console mülkünü seç; site listeni yalnızca sen görürsün.';
    }

    public function save(): void
    {
        abort_unless(GoogleClient::canManageClient(auth()->user()), 403);

        foreach ($this->form->getState() as $key => $value) {
            Setting::put($key, $value);
        }

        Notification::make()->title('Kaydedildi')->success()->send();
    }
}
