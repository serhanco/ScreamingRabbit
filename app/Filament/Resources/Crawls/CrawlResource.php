<?php

namespace App\Filament\Resources\Crawls;

use App\Filament\Resources\Crawls\Pages\ListCrawls;
use App\Filament\Resources\Crawls\Pages\ViewCrawl;
use App\Filament\Resources\Crawls\RelationManagers\IssuesRelationManager;
use App\Filament\Resources\Crawls\RelationManagers\PagesRelationManager;
use App\Filament\Resources\Crawls\Schemas\CrawlInfolist;
use App\Filament\Resources\Crawls\Tables\CrawlsTable;
use App\Models\Crawl;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CrawlResource extends Resource
{
    protected static ?string $model = Crawl::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $modelLabel = 'Tarama';

    protected static ?string $pluralModelLabel = 'Taramalar';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return CrawlInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CrawlsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            IssuesRelationManager::class,
            PagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCrawls::route('/'),
            'view' => ViewCrawl::route('/{record}'),
        ];
    }
}
