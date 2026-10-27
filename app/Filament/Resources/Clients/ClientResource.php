<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Concerns\ScopedToUserProjects;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Models\Client;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ClientResource extends Resource
{
    use ScopedToUserProjects {
        getEloquentQuery as private projectScopedQuery;
    }

    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'Müşteri';

    protected static ?string $pluralModelLabel = 'Müşteriler';

    /** Members only see clients they own or that have at least one project they can see. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    /** Adding and removing clients/projects is for admins; specialists only work on what is assigned to them. */
    public static function canCreate(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /** Admins delete only what they own; the primary admin anything. */
    public static function canDelete(Model $record): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isAdmin() && ($user->isPrimary() || $record->owner_id === $user->id);
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->isPrimary() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
