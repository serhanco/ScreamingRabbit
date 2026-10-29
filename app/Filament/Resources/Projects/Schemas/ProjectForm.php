<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Crawler\CrawlScheduler;
use App\Crawler\CustomExtraction;
use App\Crawler\UrlFilter;
use App\Google\GoogleClient;
use App\Models\Project;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        $defaults = config('screamingrabbit.crawl_defaults');

        return $schema
            ->components([
                Section::make('Proje')
                    ->columns(2)
                    ->schema([
                        Select::make('client_id')
                            ->label('Müşteri')
                            ->relationship('client', 'name', fn ($query) => $query->visibleTo(auth()->user()))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')->label('Müşteri adı')->required()->maxLength(255),
                            ]),
                        TextInput::make('name')
                            ->label('Proje adı')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('base_url')
                            ->label('Site adresi')
                            ->url()
                            ->required()
                            ->placeholder('https://ornek.com')
                            ->maxLength(255),
                        Select::make('platform')
                            ->label('Platform')
                            ->options(Project::PLATFORMS)
                            ->default('wordpress')
                            ->required()
                            ->helperText('WordPress seçilirse WordPress\'e özel denetimler de çalışır.'),
                        Select::make('crawl_schedule')
                            ->label('Otomatik tarama')
                            ->options(CrawlScheduler::FREQUENCIES)
                            ->placeholder('Elle başlatırım')
                            ->helperText('Seçilirse tarama gece 01:00–06:00 arasında kendiliğinden başlar.'),
                        Select::make('gsc_property')
                            ->label('Search Console mülkü')
                            // Only the signed-in admin's own site list is offered, plus this project's current property;
                            // nobody ever sees another member's Google sites.
                            ->options(fn (?Project $record): array => array_filter([$record?->gsc_property => $record?->gsc_property])
                                + rescue(fn () => GoogleClient::sites(auth()->user()), [], false))
                            ->disabled(fn (): bool => ! GoogleClient::connected(auth()->user()))
                            ->searchable()
                            ->placeholder('Bağlı değil')
                            ->helperText(fn (?Project $record): string => self::gscHelp($record)),
                    ]),
                Section::make('Tarama ayarları')
                    ->description('Boş bırakılan alanlar varsayılan değeri kullanır. Müşteri sitesini yormamak için düşük değerlerle başlayın.')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('crawl_settings.max_urls')
                            ->label('En fazla URL')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder((string) $defaults['max_urls']),
                        TextInput::make('crawl_settings.concurrency')
                            ->label('Eşzamanlı istek')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->placeholder((string) $defaults['concurrency']),
                        TextInput::make('crawl_settings.delay_ms')
                            ->label('İstekler arası bekleme (ms)')
                            ->numeric()
                            ->minValue(0)
                            ->placeholder((string) $defaults['delay_ms']),
                        TextInput::make('crawl_settings.user_agent')
                            ->label('User-Agent')
                            ->maxLength(255)
                            ->placeholder($defaults['user_agent'])
                            ->datalist(array_values(config('screamingrabbit.user_agents')))
                            ->helperText('Listeden Googlebot, Bingbot veya Chrome seçebilir ya da kendin yazabilirsin.'),
                        Toggle::make('crawl_settings.respect_robots')
                            ->label('robots.txt kurallarına uy')
                            ->default($defaults['respect_robots']),
                        Toggle::make('crawl_settings.check_external')
                            ->label('Dış linkleri kontrol et')
                            ->helperText('Başka sitelere verilen linkler tarama sonunda bir kez kontrol edilir (en fazla '.$defaults['max_external'].').')
                            ->default($defaults['check_external']),
                        Textarea::make('crawl_settings.include')
                            ->label('Sadece şunları tara')
                            ->rows(3)
                            ->placeholder("/blog/\n/urun/")
                            ->helperText('Her satıra bir kalıp (regex). Boşsa tüm site taranır.')
                            ->rule(fn (): \Closure => self::regexRule()),
                        Textarea::make('crawl_settings.exclude')
                            ->label('Şunları tarama')
                            ->rows(3)
                            ->placeholder("/etiket/\n\\?replytocom=")
                            ->helperText('Her satıra bir kalıp (regex). Eşleşen URL\'ler atlanır.')
                            ->rule(fn (): \Closure => self::regexRule()),
                    ]),
                Section::make('Şifreli site (staging) erişimi')
                    ->description('Test/staging sitesi şifreyle korunuyorsa veya erişim için özel başlık gerekiyorsa buraya girin. Bilgiler şifreli saklanır ve yalnızca bu projenin alan adına gönderilir; dış sitelere hiç gönderilmez.')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextInput::make('crawl_auth.user')->label('Kullanıcı adı (HTTP basic auth)')->maxLength(120)->autocomplete(false),
                        TextInput::make('crawl_auth.password')->label('Şifre')->password()->revealable()->maxLength(200)->autocomplete('new-password'),
                        Repeater::make('crawl_auth.headers')
                            ->label('Ek HTTP başlıkları (çerez dahil)')
                            ->maxItems(5)
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Başlık ekle')
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('name')->label('Ad')->required()->maxLength(60)->regex('/^[A-Za-z0-9-]+$/')->placeholder('Cookie'),
                                TextInput::make('value')->label('Değer')->required()->maxLength(1000)->placeholder('staging_access=abc123'),
                            ]),
                    ]),
                Section::make('Özel arama ve veri çıkarma')
                    ->description('Her sayfanın kaynak kodunda aranır. Örnekler: Google Analytics kodu olmayan sayfalar ("G-ABC123" geçmeyen), eski telefon numarası geçen sayfalar, yazar adı (XPath: //*[@class="author"]). Sonuçlar tarama sayfasında ve CSV\'de görünür; sonraki taramadan itibaren geçerli.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Repeater::make('crawl_settings.custom')
                            ->hiddenLabel()
                            ->maxItems(CustomExtraction::MAX_RULES)
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('Arama ekle')
                            ->schema([
                                TextInput::make('name')->label('Ad')->required()->maxLength(40)->distinct(),
                                Select::make('type')->label('Tür')->options(CustomExtraction::TYPES)->required()->default('contains')->live(),
                                TextInput::make('value')->label('Aranan / ifade')->required()->maxLength(300)
                                    ->rule(fn (callable $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                        if ($get('type') === 'regex' && @preg_match(CustomExtraction::pattern((string) $value), '') === false) {
                                            $fail('Geçersiz regex.');
                                        }
                                        if ($get('type') === 'xpath' && ! CustomExtraction::isValidXpath((string) $value)) {
                                            $fail('Geçersiz XPath.');
                                        }
                                    }),
                            ]),
                    ]),
            ]);
    }

    private static function gscHelp(?Project $record): string
    {
        $user = auth()->user();
        $linkedBy = $record?->gsc_user_id;
        $problem = null;
        $stale = filled($record?->gsc_property) && $record->gscConnection() === null;

        return match (true) {
            $stale => 'Bu mülkü bağlayan hesabın Google bağlantısı yok; veriler güncellenmiyor.'.(GoogleClient::connected($user) ? ' Kendi listenden mülkü yeniden seçebilirsin.' : ''),
            GoogleClient::connected($user) && $linkedBy !== null && $linkedBy !== $user->id => 'Veriler '.($record->gscUser?->name ?? 'başka bir üye').'\'nin Google bağlantısıyla çekiliyor. Kendi listenden seçersen senin bağlantına geçer.',
            GoogleClient::connected($user) && ($problem = GoogleClient::sitesProblem($user)) !== null => $problem,
            GoogleClient::connected($user) => 'Tıklama ve gösterim verileri bu mülkten senin Google bağlantınla günlük çekilir.',
            GoogleClient::canConnect($user) => 'Önce "Google bağlantısı" sayfasından kendi Google hesabını bağla.',
            filled($record?->gsc_property) => 'Veriler proje sahibinin Google bağlantısıyla çekilir.',
            default => 'Search Console mülkünü proje sahibi bağlar.',
        };
    }

    private static function regexRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($line = UrlFilter::firstInvalid($value)) {
                $fail("Geçersiz kalıp: {$line}");
            }
        };
    }
}
