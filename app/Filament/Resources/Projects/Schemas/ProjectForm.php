<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Google\GoogleClient;
use App\Models\Project;
use Filament\Forms\Components\Select;
use App\Crawler\UrlFilter;
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
                            ->relationship('client', 'name')
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
                        Select::make('gsc_property')
                            ->label('Search Console mülkü')
                            ->options(fn (): array => rescue(fn () => GoogleClient::sites(), [], false))
                            ->searchable()
                            ->placeholder('Bağlı değil')
                            ->helperText(fn (): string => GoogleClient::connected() ? 'Tıklama ve gösterim verileri bu mülkten günlük çekilir.' : 'Önce "Google bağlantısı" sayfasından Google hesabını bağla.'),
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
            ]);
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
