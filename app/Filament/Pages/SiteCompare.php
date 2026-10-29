<?php

namespace App\Filament\Pages;

use App\Crawler\SiteComparison;
use App\Models\Crawl;
use App\Models\Project;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Staging ↔ live comparison: two finished crawls of different sites, matched by URL path, before a launch or a
 * migration (docs/09 Faz 5).
 *
 * @property-read Schema $form
 */
class SiteCompare extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Site karşılaştırma';

    protected static ?string $title = 'Staging ↔ canlı site karşılaştırma';

    protected static ?string $slug = 'site-karsilastirma';

    protected static ?int $navigationSort = 8;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description('İki tamamlanmış taramayı URL yoluna göre eşleştirir (alan adı dikkate alınmaz). Önce canlı siteyi, sonra test (staging) sitesini ayrı projeler olarak tarayın.')
                    ->columns(2)
                    ->schema([
                        Select::make('live')->label('Canlı site taraması')->options(fn () => self::crawlOptions())->searchable()->required(),
                        Select::make('staging')->label('Test (staging) taraması')->options(fn () => self::crawlOptions())->searchable()->required()->different('live'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('compare')
                ->footer([Actions::make([Action::make('compare')->label('Karşılaştır')->submit('compare')])]),
            View::make('site-compare.results')->visible(fn (): bool => $this->result !== null),
        ]);
    }

    public function compare(): void
    {
        $state = $this->form->getState();
        $crawls = self::visibleCrawls()->whereIn('id', [$state['live'], $state['staging']])->get()->keyBy('id');
        abort_unless($crawls->count() === 2, 404);

        $this->result = (new SiteComparison($crawls[$state['live']], $crawls[$state['staging']]))->data();
    }

    /** @return array<int, string> */
    private static function crawlOptions(): array
    {
        return self::visibleCrawls()->with('project:id,name,base_url')->latest('id')->limit(200)->get()
            ->mapWithKeys(fn (Crawl $crawl) => [$crawl->id => $crawl->project->name.' · '.parse_url($crawl->project->base_url, PHP_URL_HOST).' · '.($crawl->finished_at ?? $crawl->created_at)->timezone(config('screamingrabbit.alerts.timezone'))->format('d.m.Y H:i')])
            ->all();
    }

    private static function visibleCrawls()
    {
        return Crawl::query()->where('status', 'completed')->whereIn('project_id', Project::query()->visibleTo(auth()->user())->select('projects.id'));
    }
}
