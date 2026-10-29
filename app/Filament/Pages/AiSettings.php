<?php

namespace App\Filament\Pages;

use App\Ai\Ai;
use App\Ai\AiException;
use App\Models\AiRequest;
use App\Models\Project;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * AI providers (docs/09 Faz 7, AI-0): API keys, the cheap and strong model of each provider with its price, which
 * provider and tier each task uses, a fallback provider, the monthly spending limit and this month's usage.
 *
 * @property-read Schema $form
 */
class AiSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'AI sağlayıcıları';

    protected static ?string $title = 'AI sağlayıcıları';

    protected static ?string $slug = 'ai-saglayicilar';

    protected static ?int $navigationSort = 93;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isPrimary();
    }

    /** @return list<string> */
    private static function keys(): array
    {
        $keys = ['ai_default_provider', 'ai_fallback_provider', 'ai_monthly_budget', 'ai_url_compatible'];
        foreach (array_keys(Ai::PROVIDERS) as $provider) {
            $keys[] = "ai_key_{$provider}";
            foreach (array_keys(Ai::TIERS) as $tier) {
                array_push($keys, "ai_model_{$provider}_{$tier}", "ai_price_{$provider}_{$tier}_in", "ai_price_{$provider}_{$tier}_out");
            }
        }
        foreach (array_keys(Ai::TASKS) as $task) {
            array_push($keys, "ai_task_tier_{$task}", "ai_task_provider_{$task}");
        }

        return $keys;
    }

    public function mount(): void
    {
        $state = [];
        foreach (self::keys() as $key) {
            $state[$key] = Setting::get($key);
        }
        foreach (Ai::ANTHROPIC_DEFAULTS as $tier => $default) {
            $state["ai_model_anthropic_{$tier}"] ??= $default['model'];
            $state["ai_price_anthropic_{$tier}_in"] ??= $default['in'];
            $state["ai_price_anthropic_{$tier}_out"] ??= $default['out'];
        }
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $providers = [];
        foreach (Ai::PROVIDERS as $provider => $label) {
            $fields = [];
            if ($provider === 'compatible') {
                $fields[] = TextInput::make('ai_url_compatible')->label('Adres (…/v1)')->url()->placeholder('https://openrouter.ai/api/v1')->columnSpanFull();
            }
            $fields[] = TextInput::make("ai_key_{$provider}")->label('API anahtarı')->password()->revealable()->columnSpanFull();
            foreach (Ai::TIERS as $tier => $tierLabel) {
                $fields[] = TextInput::make("ai_model_{$provider}_{$tier}")->label($tierLabel)->maxLength(100);
                $fields[] = TextInput::make("ai_price_{$provider}_{$tier}_in")->label('Girdi $ / 1M token')->numeric()->minValue(0);
                $fields[] = TextInput::make("ai_price_{$provider}_{$tier}_out")->label('Çıktı $ / 1M token')->numeric()->minValue(0);
            }
            $providers[] = Section::make($label.(Ai::configured($provider) ? ' · hazır' : ''))
                ->collapsible()->collapsed(! Ai::configured($provider) && $provider !== 'anthropic')
                ->columns(3)->schema($fields);
        }

        $tasks = [];
        foreach (Ai::TASKS as $task => [$label, $tier]) {
            $tasks[] = Select::make("ai_task_tier_{$task}")->label($label)->options(Ai::TIERS)->placeholder('Varsayılan: '.Ai::TIERS[$tier]);
            $tasks[] = Select::make("ai_task_provider_{$task}")->label('Sağlayıcı')->options(Ai::PROVIDERS)->placeholder('Varsayılan sağlayıcı');
        }

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Bu ay')
                    ->schema([Text::make(fn (): string => $this->usage())]),
                Section::make('Genel')
                    ->description('Anahtarlar şifreli saklanır ve yalnızca bu uygulamada kullanılır; WordPress eklentisine gönderilmez. AI\'ın yazdığı her şey öneridir, onaylanmadan hiçbir yere yazılmaz. Aynı soru 30 gün içinde tekrar sorulursa kayıtlı cevap kullanılır, ücret ödenmez.')
                    ->columns(3)
                    ->schema([
                        Select::make('ai_default_provider')->label('Varsayılan sağlayıcı')->options(Ai::PROVIDERS)->placeholder('Anahtarı olan ilk sağlayıcı'),
                        Select::make('ai_fallback_provider')->label('Yedek sağlayıcı (hata olursa)')->options(Ai::PROVIDERS)->placeholder('Yok'),
                        TextInput::make('ai_monthly_budget')->label('Aylık bütçe ($, 0 = sınırsız)')->numeric()->minValue(0),
                    ]),
                ...$providers,
                Section::make('Görevler')
                    ->description('Her görev için model seviyesi ve sağlayıcı. Boş bırakılanlar varsayılanı kullanır.')
                    ->collapsible()->collapsed()
                    ->columns(2)->schema($tasks),
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
                    ]),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label('Bağlantıyı dene')->color('gray')->icon(Heroicon::OutlinedSignal)
                ->schema([Select::make('provider')->label('Sağlayıcı')->required()->default(fn () => collect(array_keys(Ai::PROVIDERS))->first(fn ($p) => Ai::configured($p)))
                    ->options(fn (): array => array_filter(Ai::PROVIDERS, fn ($label, $provider) => Ai::configured($provider), ARRAY_FILTER_USE_BOTH))])
                ->modalDescription('Kaydedilmiş ayarla ekonomik modele kısa bir soru gönderilir (ücreti bir kuruştan azdır).')
                ->action(fn (array $data) => $this->test($data['provider'])),
        ];
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        foreach (self::keys() as $key) {
            Setting::put($key, isset($state[$key]) ? trim((string) $state[$key]) : null);
        }

        Notification::make()->title('Kaydedildi')->success()->send();
    }

    private function test(string $provider): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $answer = Ai::driver($provider)->complete(Ai::model($provider, 'cheap'), 'Answer with one word.', 'Say OK.', 20, 'cheap');
            Notification::make()->title('Bağlantı çalışıyor')->body($answer['model'].': '.mb_substr(trim($answer['text']), 0, 40))->success()->send();
        } catch (AiException $e) {
            Notification::make()->title('Bağlanamadı')->body($e->getMessage())->danger()->send();
        }
    }

    private function usage(): string
    {
        $month = AiRequest::where('created_at', '>=', now()->startOfMonth());
        $budget = (float) Setting::get('ai_monthly_budget', 0);
        $text = sprintf('%s $ harcandı%s · %d istek (%d hata).', number_format(Ai::monthSpend(), 2), $budget > 0 ? ' / '.number_format($budget, 2).' $ bütçe' : '',
            (clone $month)->count(), (clone $month)->where('status', 'error')->count());

        $top = (clone $month)->whereNotNull('project_id')->selectRaw('project_id, sum(cost_usd) as total')->groupBy('project_id')->orderByDesc('total')->limit(3)->pluck('total', 'project_id');
        if ($top->isNotEmpty()) {
            $names = Project::whereIn('id', $top->keys())->pluck('name', 'id');
            $text .= ' En çok: '.$top->map(fn ($total, $id) => ($names[$id] ?? '#'.$id).' '.number_format((float) $total, 2).' $')->implode(', ').'.';
        }

        return $text;
    }
}
