<?php

namespace App\Filament\Resources\WatchedPages;

use App\Filament\Concerns\ScopedToUserProjects;
use App\Filament\Resources\WatchedPages\Pages\CreateWatchedPage;
use App\Filament\Resources\WatchedPages\Pages\EditWatchedPage;
use App\Filament\Resources\WatchedPages\Pages\ListWatchedPages;
use App\Filament\Resources\WatchedPages\RelationManagers\ChangesRelationManager;
use App\Models\WatchedPage;
use App\Monitoring\PageWatcher;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Critical pages (docs/09 Faz 4): a few important URLs per project, checked hourly or daily for status, redirect,
 * noindex, canonical and title changes.
 */
class WatchedPageResource extends Resource
{
    use ScopedToUserProjects;

    protected static string $permission = 'monitor';

    protected static ?string $model = WatchedPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $modelLabel = 'Kritik sayfa';

    protected static ?string $pluralModelLabel = 'Kritik sayfalar';

    public static function getNavigationBadge(): ?string
    {
        $changed = static::getEloquentQuery()->where('last_changed_at', '>=', now()->subDay())->count();

        return $changed > 0 ? (string) $changed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Kritik sayfa')
                ->description('Ana sayfa, hizmet ve ürün sayfaları gibi kaybetmek istemediğin sayfalar. Durum kodu, yönlendirme, noindex, canonical veya title değişirse uyarı gelir.')
                ->columns(2)
                ->schema([
                    Select::make('project_id')
                        ->label('Proje')
                        ->relationship('project', 'name', fn ($query) => $query->visibleTo(auth()->user()))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live(),
                    TextInput::make('url')
                        ->label('Adres')
                        ->url()
                        ->required()
                        ->maxLength(500)
                        ->rules([fn (callable $get, ?Model $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                            $count = WatchedPage::where('project_id', $get('project_id'))->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->count();
                            if ($count >= WatchedPage::MAX_PER_PROJECT) {
                                $fail('Bir projede en fazla '.WatchedPage::MAX_PER_PROJECT.' kritik sayfa olabilir.');
                            }
                        }]),
                    Select::make('frequency')
                        ->label('Kontrol sıklığı')
                        ->options(WatchedPage::FREQUENCIES)
                        ->default('hourly')
                        ->required(),
                    Toggle::make('is_active')->label('Etkin')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.name')->label('Proje')->searchable()->sortable(),
                TextColumn::make('url')->label('Adres')->limit(50)->searchable()->url(fn (WatchedPage $record): string => $record->url, shouldOpenInNewTab: true),
                TextColumn::make('state.status')->label('Durum')->badge()->placeholder('—')
                    ->formatStateUsing(fn ($state): string => PageWatcher::show('status', $state))
                    ->color(fn ($state): string => match (true) {
                        $state === null => 'gray',
                        $state < 300 => 'success',
                        $state < 400 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('state.noindex')->label('Noindex')->formatStateUsing(fn ($state): string => PageWatcher::show('noindex', $state))->color(fn ($state): ?string => $state ? 'danger' : null)->placeholder('—'),
                TextColumn::make('state.title')->label('Title')->limit(40)->placeholder('—'),
                TextColumn::make('frequency')->label('Sıklık')->formatStateUsing(fn (string $state): string => WatchedPage::FREQUENCIES[$state] ?? $state),
                TextColumn::make('last_changed_at')->label('Son değişiklik')->since()->placeholder('Değişmedi')->sortable()
                    ->color(fn (WatchedPage $record): ?string => $record->last_changed_at?->gt(now()->subDay()) ? 'warning' : null),
                TextColumn::make('last_checked_at')->label('Son kontrol')->since()->placeholder('Henüz yok'),
                ToggleColumn::make('is_active')->label('Etkin')->disabled(fn (WatchedPage $record): bool => ! static::canEdit($record)),
            ])
            ->defaultSort('last_changed_at', 'desc')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ChangesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWatchedPages::route('/'),
            'create' => CreateWatchedPage::route('/create'),
            'edit' => EditWatchedPage::route('/{record}/edit'),
        ];
    }
}
