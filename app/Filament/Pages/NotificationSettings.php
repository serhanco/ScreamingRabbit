<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Monitoring\Channels;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Where alerts go: e-mail through a Hostinger mailbox, Telegram through a bot, plus the external heartbeat URL.
 *
 * @property-read Schema $form
 */
class NotificationSettings extends Page
{
    private const KEYS = ['alert_email', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'telegram_bot_token', 'telegram_chat_id', 'heartbeat_url'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Bildirim ayarları';

    protected static ?string $title = 'Bildirim ayarları';

    protected static ?int $navigationSort = 90;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $values = collect(self::KEYS)->mapWithKeys(fn (string $key) => [$key => Setting::get($key)])->all();
        $values['smtp_host'] ??= config('screamingrabbit.alerts.smtp_host');
        $values['smtp_port'] ??= config('screamingrabbit.alerts.smtp_port');

        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('E-posta')
                    ->description('Uyarılar Hostinger\'da açtığın bir e-posta hesabından (örn. bildirim@alanadin.com) gönderilir.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('alert_email')->label('Uyarıların gideceği adres')->email(),
                        TextInput::make('smtp_username')->label('Gönderen e-posta hesabı')->email()->helperText('hPanel → E-postalar\'da açtığın hesap.'),
                        TextInput::make('smtp_password')->label('Gönderen hesabın şifresi')->password()->revealable(),
                        TextInput::make('smtp_host')->label('SMTP sunucusu'),
                        TextInput::make('smtp_port')->label('SMTP portu')->numeric(),
                    ]),
                Section::make('Telegram')
                    ->description('1) Telegram\'da @BotFather\'a /newbot yaz, verdiği anahtarı aşağıya yapıştır. 2) Yeni botuna herhangi bir mesaj at. 3) "Sohbeti bul" düğmesine bas.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('telegram_bot_token')->label('Bot anahtarı')->password()->revealable(),
                        TextInput::make('telegram_chat_id')
                            ->label('Sohbet kimliği')
                            ->suffixAction(Action::make('findChat')
                                ->label('Sohbeti bul')
                                ->icon(Heroicon::OutlinedMagnifyingGlass)
                                ->action(function (Get $get, Set $set): void {
                                    $chat = $get('telegram_bot_token') ? Channels::findTelegramChat($get('telegram_bot_token')) : null;
                                    if ($chat === null) {
                                        Notification::make()->title('Sohbet bulunamadı')->body('Bot anahtarını kontrol et ve bota bir mesaj gönderip tekrar dene.')->warning()->send();

                                        return;
                                    }
                                    $set('telegram_chat_id', $chat);
                                    Notification::make()->title('Sohbet bulundu, kaydetmeyi unutma')->success()->send();
                                })),
                    ]),
                Section::make('Uygulamanın kendisi için kalp atışı')
                    ->description('Sunucu tamamen düşerse uygulama da haber veremez. Healthchecks.io\'da ücretsiz bir kontrol aç, ping adresini buraya yapıştır; dakikada bir ping gelmezse sana o haber verir.')
                    ->schema([
                        TextInput::make('heartbeat_url')->label('Ping adresi')->url()->placeholder('https://hc-ping.com/...'),
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
                        Action::make('test')->label('Test bildirimi gönder')->color('gray')->action('sendTest'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        foreach ($this->form->getState() as $key => $value) {
            if (in_array($key, self::KEYS, true)) {
                Setting::put($key, $value);
            }
        }

        Notification::make()->title('Kaydedildi')->success()->send();
    }

    public function sendTest(): void
    {
        $this->save();
        $result = Channels::deliver('ScreamingRabbit test bildirimi', 'Bu mesajı görüyorsan uyarılar sana ulaşıyor.');

        $labels = ['email' => 'E-posta', 'telegram' => 'Telegram'];
        $lines = collect($result)->map(fn ($ok, $channel) => $labels[$channel].': '.match ($ok) {
            true => 'gönderildi',
            false => 'gönderilemedi (bilgileri kontrol et)',
            null => 'ayarlanmamış',
        })->implode("\n");

        Notification::make()->title('Test sonucu')->body($lines)->{in_array(false, $result, true) ? 'warning' : 'success'}()->send();
    }
}
