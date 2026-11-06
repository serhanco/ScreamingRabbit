<?php

namespace App\Filament\Pages;

use App\Crawler\CrawlRunner;
use App\Crawler\UrlNormalizer;
use App\Exports\SpeedXlsx;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Crawl;
use App\Models\Project;
use App\Models\Setting;
use App\Performance\SpeedScan;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Site-wide speed scan: a home page or sitemap address goes in, the site is crawled (same host only, every URL once)
 * and each page is then measured with PageSpeed Insights on mobile and desktop. Results open on the crawl page and
 * download as an Excel file.
 *
 * @property-read Schema $form
 */
class SpeedScans extends Page implements HasTable
{
    use InteractsWithTable;

    public const MAX_URLS = 5000;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationLabel = 'Hız taraması';

    protected static ?string $title = 'Site geneli hız taraması';

    protected static ?string $slug = 'hiz-taramasi';

    protected static ?int $navigationSort = 6;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['strategies' => ['mobile', 'desktop'], 'max_urls' => 500]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description(fn (): string => 'Site taranır, aynı alan adındaki her sayfa bir kez listelenir ve hepsi Google PageSpeed Insights ile ölçülür. '
                        .'Dakikada yaklaşık '.SpeedScan::concurrency().' ölçüm yapılır; 500 sayfa mobil + masaüstü yaklaşık '.(int) ceil(1000 / SpeedScan::concurrency()).' dakika sürer.'
                        .(filled(Setting::get('pagespeed_api_key')) ? '' : ' Google ayarlarına ücretsiz PageSpeed API anahtarı eklerseniz ölçüm yaklaşık 4 kat hızlanır ve kota dolmaz.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('address')->label('Ana sayfa veya sitemap adresi')->url()->required()->columnSpanFull()
                            ->placeholder('https://ornek.com/ veya https://ornek.com/sitemap.xml')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, callable $set, callable $get) {
                                if (blank($get('project')) && ($project = self::matchingProject((string) $state))) {
                                    $set('project', $project->id);
                                }
                            }),
                        Select::make('project')->label('Proje')->required()->searchable()
                            ->options(fn () => self::projects()->orderBy('name')->get()->mapWithKeys(fn (Project $p) => [$p->id => $p->name.' · '.parse_url($p->base_url, PHP_URL_HOST)])->all())
                            ->helperText('Adres projenin alan adında olmalı; eşleşen proje kendiliğinden seçilir.'),
                        TextInput::make('max_urls')->label('En fazla sayfa')->numeric()->minValue(1)->maxValue(self::MAX_URLS)->required(),
                        CheckboxList::make('strategies')->label('Cihaz')->options(['mobile' => 'Mobil', 'desktop' => 'Masaüstü'])->required()->columns(2),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('start')
                ->footer([Actions::make([Action::make('start')->label('Hız taramasını başlat')->icon(Heroicon::OutlinedPlay)->submit('start')])]),
            Section::make('Hız taramaları')->schema([EmbeddedTable::make()]),
        ]);
    }

    public function start(): void
    {
        $state = $this->form->getState();
        $project = self::projects()->whereKey($state['project'])->first();
        abort_if($project === null || ! auth()->user()->canDo('crawl', $project->id), 403);

        $address = UrlNormalizer::resolve(trim((string) $state['address']), $project->base_url);
        if ($address === null || ! UrlNormalizer::isInternal($address, $project->base_url)) {
            Notification::make()->title('Adres projenin alan adında değil')->body('Proje: '.$project->base_url)->danger()->send();

            return;
        }

        if ($active = $project->crawls()->whereIn('status', ['pending', 'running'])->first()) {
            Notification::make()->title('Bu proje için bir tarama zaten sürüyor')->warning()->send();
            $this->redirect(CrawlResource::getUrl('view', ['record' => $active]));

            return;
        }

        $isSitemap = self::isSitemap($address);
        $crawl = CrawlRunner::start($project, [
            // Speed scans always follow links ("discovered" URLs), seeded from the sitemap when one is given.
            'mode' => 'spider',
            'start_url' => $isSitemap ? null : $address,
            'sitemap_url' => $isSitemap ? $address : null,
            'max_urls' => min(self::MAX_URLS, max(1, (int) $state['max_urls'])),
            'speed_strategies' => array_values($state['strategies']),
        ], speedScan: true);

        Notification::make()->title('Hız taraması başlatıldı')->body('Önce site taranır, ardından sayfalar tek tek ölçülür.')->success()->send();
        $this->redirect(CrawlResource::getUrl('view', ['record' => $crawl]));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Crawl::query()->with('project:id,name,base_url')->where('speed_scan', true)->whereIn('project_id', self::projects()->select('projects.id')))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('project.name')->label('Proje'),
                TextColumn::make('created_at')->label('Başlangıç')->dateTime('d.m.Y H:i', config('screamingrabbit.alerts.timezone')),
                TextColumn::make('speed_state')->label('Durum')->badge()
                    ->state(fn (Crawl $record): string => self::stateLabel($record))
                    ->color(fn (Crawl $record): string => match (true) {
                        $record->status !== 'completed' && ! $record->isActive() => 'danger',
                        str_starts_with(self::stateLabel($record), 'Tamam') => 'success',
                        default => 'warning',
                    }),
                TextColumn::make('mobile_avg')->label('Ort. mobil puan')->placeholder('—')
                    ->state(fn (Crawl $record): ?int => self::summaryOf($record)['strategies']['mobile']['average'] ?? null),
                TextColumn::make('desktop_avg')->label('Ort. masaüstü puan')->placeholder('—')
                    ->state(fn (Crawl $record): ?int => self::summaryOf($record)['strategies']['desktop']['average'] ?? null),
            ])
            ->recordUrl(fn (Crawl $record): string => CrawlResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                Action::make('xlsx')->label('Excel')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn (Crawl $record): bool => $record->speedResults()->where('state', 'done')->exists())
                    ->action(fn (Crawl $record) => (new SpeedXlsx($record))->download()),
            ])
            ->emptyStateHeading('Henüz hız taraması yok');
    }

    public static function stateLabel(Crawl $crawl): string
    {
        if ($crawl->isActive()) {
            return 'Site taranıyor';
        }
        if ($crawl->status !== 'completed') {
            return Crawl::STATUSES[$crawl->status] ?? $crawl->status;
        }
        $summary = self::summaryOf($crawl);

        return $summary['pending'] > 0 ? 'Ölçülüyor %'.$summary['percent'] : 'Tamamlandı ('.$summary['done'].' ölçüm)';
    }

    /** @return array<string, mixed> */
    private static function summaryOf(Crawl $crawl): array
    {
        static $cache = [];

        return $cache[$crawl->id] ??= SpeedScan::summary($crawl);
    }

    public static function isSitemap(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_ends_with($path, '.xml') || str_ends_with($path, '.xml.gz');
    }

    private static function matchingProject(string $url): ?Project
    {
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url(trim($url), PHP_URL_HOST)));
        if ($host === '') {
            return null;
        }

        return self::projects()->get()->first(fn (Project $p) => preg_replace('/^www\./', '', strtolower((string) parse_url($p->base_url, PHP_URL_HOST))) === $host);
    }

    private static function projects()
    {
        return Project::query()->visibleTo(auth()->user());
    }
}
