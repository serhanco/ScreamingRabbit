<?php

namespace App\Filament\Pages;

use App\Crawler\BotAccess;
use App\Crawler\RobotsTxt;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tests URLs against a robots.txt (fetched from the site or edited by hand) for chosen bots, showing the deciding rule,
 * so a planned robots.txt change can be tried before it goes live.
 *
 * @property-read Schema $form
 */
class RobotsTester extends Page
{
    public const MAX_URLS = 100;

    public const DEFAULT_BOTS = ['googlebot', 'bingbot', 'oai-searchbot', 'gptbot', 'claudebot', 'google-extended'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'robots.txt testi';

    protected static ?string $title = 'robots.txt testi';

    protected static ?string $slug = 'robots-testi';

    protected static ?int $navigationSort = 7;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<int, array{url: string, bots: array<string, array{allowed: bool, rule: ?string}>}> */
    public array $results = [];

    /** @var array<string, string> token => bot name of the last test */
    public array $testedBots = [];

    public function mount(): void
    {
        $this->form->fill(['bots' => self::DEFAULT_BOTS]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('robots.txt')
                    ->description('Sitenin robots.txt dosyasını getirin, isterseniz değiştirin; test canlı siteyi etkilemez.')
                    ->schema([
                        TextInput::make('site')->label('Site adresi')->url()->placeholder('https://ornek.com')
                            ->suffixAction(Action::make('fetch')->label('Getir')->icon(Heroicon::OutlinedArrowDownTray)->action(fn () => $this->fetch())),
                        Textarea::make('robots')->label('robots.txt içeriği')->rows(10)->maxLength(500_000)->extraInputAttributes(['class' => 'font-mono']),
                    ]),
                Section::make('Test edilecek adresler')
                    ->schema([
                        Textarea::make('urls')->label('Adresler (her satıra bir tane, en fazla '.self::MAX_URLS.')')->rows(6)->required()
                            ->placeholder("https://ornek.com/\nhttps://ornek.com/wp-admin/\n/urun/?renk=mavi"),
                        CheckboxList::make('bots')->label('Botlar')->required()->columns(3)
                            ->options(collect(BotAccess::BOTS)->map(fn (array $bot) => $bot[0])->all()),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('test')
                ->footer([
                    Actions::make([Action::make('test')->label('Test et')->submit('test')]),
                ]),
            View::make('robots-tester.results')->visible(fn (): bool => $this->results !== []),
        ]);
    }

    public function fetch(): void
    {
        $site = trim((string) ($this->data['site'] ?? ''));
        $parts = parse_url($site);
        if (! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])) {
            Notification::make()->title('Geçerli bir site adresi yazın (https://… ile)')->danger()->send();

            return;
        }

        $url = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').'/robots.txt';
        try {
            $response = Http::withUserAgent(config('screamingrabbit.crawl_defaults.user_agent'))->timeout(10)->get($url);
        } catch (Throwable) {
            Notification::make()->title('robots.txt alınamadı')->body('Site yanıt vermedi.')->danger()->send();

            return;
        }

        if (! $response->successful()) {
            $this->data['robots'] = '';
            Notification::make()->title("robots.txt yok (HTTP {$response->status()})")->body('Dosya yoksa her şey taranabilir; içeriği elle yazıp deneyebilirsiniz.')->warning()->send();

            return;
        }

        $this->data['robots'] = mb_substr($response->body(), 0, 500_000);
        Notification::make()->title('robots.txt getirildi')->success()->send();
    }

    public function test(): void
    {
        $state = $this->form->getState();
        $base = $this->baseUrl($state);
        $robots = (string) ($state['robots'] ?? '');

        $urls = collect(preg_split('/\r\n|\r|\n/', (string) $state['urls']))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->unique()
            ->take(self::MAX_URLS)
            ->map(fn (string $url) => str_starts_with($url, '/') && $base !== null ? $base.$url : $url)
            ->values();

        $this->testedBots = collect(BotAccess::BOTS)->only($state['bots'])->map(fn (array $bot) => $bot[0])->all();
        $parsers = collect($this->testedBots)->mapWithKeys(fn (string $name, string $token) => [$token => new RobotsTxt($robots, $token)]);

        $this->results = $urls->map(fn (string $url) => [
            'url' => $url,
            'bots' => $parsers->map(fn (RobotsTxt $parser) => $parser->decide($url))->all(),
        ])->all();
    }

    /** @param  array<string, mixed>  $state */
    private function baseUrl(array $state): ?string
    {
        $parts = parse_url(trim((string) ($state['site'] ?? '')));

        return empty($parts['host']) ? null : ($parts['scheme'] ?? 'https').'://'.$parts['host'];
    }
}
