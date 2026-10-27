<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Team members: who can sign in, what role they have and which projects they work on. Primary admin only: other
 * admins only see their own projects, so they cannot hand out access to the rest.
 * Every member sets up their own 2FA on first sign-in (the panel requires it).
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'Üye';

    protected static ?string $pluralModelLabel = 'Ekip';

    protected static ?int $navigationSort = 89;

    public static function canAccess(): bool
    {
        return auth()->user()?->isPrimary() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label('Ad soyad')->required()->maxLength(255),
                TextInput::make('email')->label('E-posta (giriş adı)')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
                TextInput::make('password')->label('Şifre')->password()->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->minLength(10)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(fn (string $operation): string => $operation === 'create' ? 'En az 10 karakter. Üye ilk girişte kendi 2FA\'sını kurar; şifreyi sonra değiştirebilir.' : 'Boş bırakırsan şifre değişmez.'),
                Select::make('role')->label('Rol')->options(User::ROLES)->required()->default('specialist')->live()
                    ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                    ->helperText(fn (?User $record): ?string => $record?->is(auth()->user()) ? 'Kendi rolünü değiştiremezsin; böylece panelde hiç yönetici kalmama riski olmaz.' : null),
                Select::make('projects')
                    ->relationship('projects', 'name', fn ($query) => $query->orderBy('name'))
                    ->multiple()->preload()->searchable()
                    ->visible(fn (Get $get, ?User $record): bool => $get('role') !== 'admin' || ! ($record?->is_primary ?? false))
                    ->label(fn (Get $get): string => $get('role') === 'admin' ? 'Ek projeler (kendi eklediği projeleri zaten görür)' : 'Çalışabildiği projeler')
                    ->helperText('Uzman ve salt okunur üye yalnızca burada seçilenleri görür; uyarılar da yalnızca bu projeler için gelir.')
                    ->columnSpanFull(),
                Toggle::make('is_active')->label('Hesap açık')->default(true)->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)->helperText('Kapatılırsa üye giriş yapamaz; hesabı silmeden erişimi keser.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Ad')->searchable(),
                TextColumn::make('email')->label('E-posta')->searchable(),
                TextColumn::make('role')->label('Rol')->badge()->formatStateUsing(fn (string $state): string => explode(' (', User::ROLES[$state] ?? $state)[0]),
                TextColumn::make('projects_count')->label('Proje')->counts('projects')
                    ->formatStateUsing(fn ($state, User $record): string => $record->isPrimary() ? 'Hepsi' : (string) $state),
                IconColumn::make('has_2fa')->label('2FA')->boolean()->state(fn (User $record): bool => filled($record->app_authentication_secret)),
                IconColumn::make('is_active')->label('Açık')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->label('Düzenle'),
                Action::make('reset2fa')
                    ->label('2FA\'yı sıfırla')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('gray')
                    ->visible(fn (User $record): bool => filled($record->app_authentication_secret))
                    ->requiresConfirmation()
                    ->modalDescription('Üye telefonunu kaybettiyse kullanılır. Sonraki girişte 2FA\'yı yeniden kurması istenir.')
                    ->action(function (User $record): void {
                        $record->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
                        Notification::make()->title('2FA sıfırlandı')->success()->send();
                    }),
                DeleteAction::make()->visible(fn (User $record): bool => $record->isNot(auth()->user())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
