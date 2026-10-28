<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Monitoring\Alerts;
use App\Monitoring\Channels;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
 * Each user's own alert e-mail, Telegram chat and alert kinds, plus the shared sender mailbox, Telegram bot and
 * external heartbeat URL.
 *
 * @property-read Schema $form
 */
class NotificationSettings extends Page
{
    private const SHARED_KEYS = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'telegram_bot_token', 'heartbeat_url'];

    private const USER_KEYS = ['alert_email', 'telegram_chat_id', 'alert_kinds', 'quiet_from', 'quiet_to', 'weekly_digest'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Bildirim ayarları';

    protected static ?string $title = 'Bildirim ayarları';

    protected static ?int $navigationSort = 90;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $values = collect(self::SHARED_KEYS)->mapWithKeys(fn (string $key) => [$key => Setting::get($key)])->all();
        $values['smtp_host'] ??= config('screamingrabbit.alerts.smtp_host');
        $values['smtp_port'] ??= config('screamingrabbit.alerts.smtp_port');

        $user = auth()->user();
        $values += ['alert_email' => $user->alert_email ?? $user->email, 'telegram_chat_id' => $user->telegram_chat_id, 'alert_kinds' => $user->alert_kinds ?? array_keys(Alerts::KINDS),
            'quiet_from' => $user->quiet_from, 'quiet_to' => $user->quiet_to, 'weekly_digest' => $user->weekly_digest ?? true];

        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Benim bildirimlerim')
                    ->description('Bu ayarlar yalnızca senin hesabın için. Telegram: ortak bota herhangi bir mesaj at, sonra "Sohbeti bul" düğmesine bas.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('alert_email')->label('Uyarıların gideceği e-posta')->email(),
                        TextInput::make('telegram_chat_id')
                            ->label('Telegram sohbet kimliği')
                            ->suffixAction(Action::make('findChat')
                                ->label('Sohbeti bul')
                                ->icon(Heroicon::OutlinedMagnifyingGlass)
                                ->action(function (Get $get, Set $set): void {
                                    $token = $get('telegram_bot_token') ?: Setting::get('telegram_bot_token');
                                    $chat = $token ? Channels::findTelegramChat($token) : null;
                                    if ($chat === null) {
                                        Notification::make()->title('Sohbet bulunamadı')->body('Bot anahtarını kontrol et ve bota bir mesaj gönderip tekrar dene.')->warning()->send();

                                        return;
                                    }
                                    $set('telegram_chat_id', $chat);
                                    Notification::make()->title('Sohbet bulundu, kaydetmeyi unutma')->success()->send();
                                })),
                        CheckboxList::make('alert_kinds')->label('Hangi uyarıları alayım?')->options(Alerts::KINDS)->columns(2)->columnSpanFull()
                            ->helperText('Kritik olanlar (site erişilemiyor/düzeldi, kritik sayfa değişti) Telegram ve e-postayla, diğerleri Telegram\'la gelir; Telegram yoksa e-postayla. Aynı uyarı 6 saat içinde tekrar gönderilmez.'),
                        Select::make('quiet_from')->label('Sessiz saatler: başlangıç')->options(self::hours())->placeholder('Kapalı'),
                        Select::make('quiet_to')->label('Sessiz saatler: bitiş')->options(self::hours())->placeholder('Kapalı')
                            ->helperText('Bu saatler arasında yalnızca kritik uyarılar gelir; diğerleri bitişte tek mesajda toplanır. Türkiye saati.'),
                        Toggle::make('weekly_digest')->label('Pazartesi sabahı haftalık özet e-postası')->columnSpanFull(),
                    ]),
                Section::make('Ortak ayarlar: gönderen e-posta hesabı')
                    ->visible(fn (): bool => auth()->user()->isPrimary())
                    ->description('Tüm üyelere uyarılar Hostinger\'da açtığın tek bir e-posta hesabından (örn. bildirim@alanadin.com) gönderilir.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('smtp_username')->label('Gönderen e-posta hesabı')->email()->helperText('hPanel → E-postalar\'da açtığın hesap.'),
                        TextInput::make('smtp_password')->label('Gönderen hesabın şifresi')->password()->revealable(),
                        TextInput::make('smtp_host')->label('SMTP sunucusu'),
                        TextInput::make('smtp_port')->label('SMTP portu')->numeric(),
                    ]),
                Section::make('Ortak ayarlar: Telegram botu')
                    ->visible(fn (): bool => auth()->user()->isPrimary())
                    ->description('Bir kez yapılır: Telegram\'da @BotFather\'a /newbot yaz, verdiği anahtarı buraya yapıştır. Tüm üyeler bu botu kullanır.')
                    ->schema([
                        TextInput::make('telegram_bot_token')->label('Bot anahtarı')->password()->revealable(),
                    ]),
                Section::make('Uygulamanın kendisi için kalp atışı')
                    ->visible(fn (): bool => auth()->user()->isPrimary())
                    ->description('Sunucu tamamen düşerse uygulama da haber veremez. Healthchecks.io\'da ücretsiz bir kontrol aç, ping adresini buraya yapıştır; dakikada bir ping gelmezse sana o haber verir.')
                    ->schema([
                        TextInput::make('heartbeat_url')->label('Ping adresi')->url()->placeholder('https://hc-ping.com/...'),
                    ]),
            ]);
    }

    /** @return array<int, string> */
    private static function hours(): array
    {
        return collect(range(0, 23))->mapWithKeys(fn (int $h) => [$h => sprintf('%02d:00', $h)])->all();
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
        $state = $this->form->getState();
        // The sender mailbox, bot and heartbeat are shared by everyone, so only the primary admin changes them.
        if (auth()->user()->isPrimary()) {
            foreach (self::SHARED_KEYS as $key) {
                Setting::put($key, $state[$key] ?? null);
            }
        }
        auth()->user()->update(array_intersect_key($state, array_flip(self::USER_KEYS)));

        Notification::make()->title('Kaydedildi')->success()->send();
    }

    public function sendTest(): void
    {
        $this->save();
        $result = Channels::toUser(auth()->user()->fresh(), 'ScreamingRabbit test bildirimi', 'Bu mesajı görüyorsan uyarılar sana ulaşıyor.');

        $labels = ['email' => 'E-posta', 'telegram' => 'Telegram'];
        $lines = collect($result)->map(fn ($ok, $channel) => $labels[$channel].': '.match ($ok) {
            true => 'gönderildi',
            false => 'gönderilemedi (bilgileri kontrol et)',
            null => 'ayarlanmamış',
        })->implode("\n");

        $hint = match (true) {
            Channels::$telegramError === null => '',
            str_contains(Channels::$telegramError, 'Unauthorized') => "\nBot anahtarı yanlış: BotFather'ın verdiği anahtarı baştan sona, boşluksuz yapıştır.",
            str_contains(Channels::$telegramError, 'chat not found') => "\nSohbet kimliği yanlış ya da bota hiç mesaj yazılmamış: botu Telegram'da aç, Start'a bas, sonra kimlik kutusundaki büyüteç düğmesine (Sohbeti bul) bas.",
            default => "\nTelegram'ın cevabı: ".Channels::$telegramError,
        };

        Notification::make()->title('Test sonucu')->body($lines.$hint)->{in_array(false, $result, true) ? 'warning' : 'success'}()->send();
    }
}
