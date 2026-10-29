<?php

namespace App\Filament\Resources\RedirectChecks;

use App\Filament\Concerns\ScopedToUserProjects;
use App\Filament\Resources\RedirectChecks\Pages\CreateRedirectCheck;
use App\Filament\Resources\RedirectChecks\Pages\ListRedirectChecks;
use App\Filament\Resources\RedirectChecks\Pages\ViewRedirectCheck;
use App\Models\RedirectCheck;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Site migration check (docs/09 Faz 5 tools): paste the old site's URLs, optionally with the new URL each should
 * land on, and see where every one of them ends up.
 */
class RedirectCheckResource extends Resource
{
    use ScopedToUserProjects;

    protected static string $permission = 'crawl';

    protected static ?string $model = RedirectCheck::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $modelLabel = 'Taşıma kontrolü';

    protected static ?string $pluralModelLabel = 'Taşıma kontrolleri';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Site taşıma / yönlendirme kontrolü')
                ->description('Eski sitenin adreslerini yapıştır; her biri yönlendirmeleriyle birlikte izlenir ve nereye vardığı gösterilir. Eski adresin yanına (boşluk, sekme veya virgülle) yeni adresini yazarsan doğru yere gidip gitmediği de kontrol edilir. Excel\'den iki sütun kopyalamak yeterli.')
                ->schema([
                    Select::make('project_id')->label('Proje')
                        ->relationship('project', 'name', fn ($query) => $query->visibleTo(auth()->user()))
                        ->searchable()->preload()->required(),
                    TextInput::make('name')->label('Ad')->required()->maxLength(120)->default('Taşıma kontrolü '.now()->format('d.m.Y')),
                    Textarea::make('list')->label('Adresler (satır başına bir tane, en fazla '.RedirectCheck::MAX_URLS.')')
                        ->rows(12)->required()
                        ->placeholder("https://eskisite.com/hizmetler/implant https://yenisite.com/implant\nhttps://eskisite.com/iletisim")
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            if (RedirectCheck::parseList((string) $value) === []) {
                                $fail('Geçerli bir adres bulunamadı; adresler http:// veya https:// ile başlamalı.');
                            }
                        }),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextEntry::make('project.name')->label('Proje'),
                    TextEntry::make('progress')->label('Kontrol edilen')->state(fn (RedirectCheck $record): string => $record->progress()),
                    TextEntry::make('problems')->label('Sorunlu')
                        ->state(fn (RedirectCheck $record): int => collect($record->summary())->only(RedirectCheck::PROBLEMS)->sum())
                        ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
                ]),
            Section::make('Sonuçlar')
                ->schema([ViewEntry::make('rows')->hiddenLabel()->view('redirect-checks.results')]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Ad')->searchable(),
                TextColumn::make('project.name')->label('Proje')->searchable(),
                TextColumn::make('progress')->label('Kontrol edilen')->state(fn (RedirectCheck $record): string => $record->progress()),
                TextColumn::make('problems')->label('Sorunlu')
                    ->state(fn (RedirectCheck $record): int => collect($record->summary())->only(RedirectCheck::PROBLEMS)->sum())
                    ->badge()->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
                TextColumn::make('created_at')->label('Oluşturuldu')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRedirectChecks::route('/'),
            'create' => CreateRedirectCheck::route('/create'),
            'view' => ViewRedirectCheck::route('/{record}'),
        ];
    }
}
