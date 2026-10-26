<?php

namespace App\Filament\Resources\Tasks;

use App\Audit\RuleCatalog;
use App\Filament\Resources\Crawls\CrawlColors;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Task;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The work list: one row per problem type and project, most important first.
 */
class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $modelLabel = 'İş';

    protected static ?string $pluralModelLabel = 'İş listesi';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $open = Task::query()->whereIn('status', ['open', 'doing'])->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('project.name')->label('Proje'),
                    TextEntry::make('title')->label('İş'),
                    TextEntry::make('why')->label('Neden önce?')->columnSpanFull(),
                    Select::make('status')->label('Durum')->options(Task::STATUSES)->required(),
                    DatePicker::make('due_on')->label('Son tarih'),
                    Textarea::make('note')->label('Not')->rows(4)->columnSpanFull(),
                    Toggle::make('show_in_report')->label('Müşteri raporunda göster'),
                    TextEntry::make('baseline')->label('Tamamlandığında')
                        ->state(fn (Task $record): ?string => $record->baseline ? "{$record->baseline['at']}: etkilenen sayfalar 28 günde {$record->baseline['clicks']} tıklama" : null)
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('project'))
            ->columns([
                TextColumn::make('score')->label('Öncelik')->numeric()->sortable(),
                TextColumn::make('project.name')->label('Proje')->searchable(),
                TextColumn::make('title')->label('İş')->searchable()->wrap()
                    ->description(fn (Task $record): string => $record->why),
                TextColumn::make('severity')->label('Önem')->badge()
                    ->formatStateUsing(fn (?string $state): string => RuleCatalog::SEVERITIES[$state] ?? '—')
                    ->color(fn (?string $state): string => CrawlColors::severity((string) $state)),
                SelectColumn::make('status')->label('Durum')->options(Task::STATUSES)->selectablePlaceholder(false),
                TextColumn::make('due_on')->label('Son tarih')->date('d.m.Y')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('updated_at')->label('Güncellendi')->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('score', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Durum')->options(Task::STATUSES)->multiple()->default(['open', 'doing']),
                SelectFilter::make('project')->label('Proje')->relationship('project', 'name'),
                SelectFilter::make('source')->label('Kaynak')->options(['audit' => 'Tarama bulgusu', 'gsc' => 'Search Console']),
            ])
            ->recordActions([
                EditAction::make()->label('Aç'),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'edit' => EditTask::route('/{record}/edit'),
        ];
    }
}
