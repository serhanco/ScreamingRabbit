<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\Project;
use App\Models\User;
use App\Team\Invitations;
use Filament\Actions\Action;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Who works on the project and what each of them may change. Only the project's owner and the primary admin see it;
 * they invite people by e-mail and pick the permissions on the invitation.
 */
class MembersRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'members';

    protected static ?string $title = 'Ekip ve yetkiler';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isAdmin() && ($user->isPrimary() || $ownerRecord->owner_id === $user->id);
    }

    public static function permissionsField(): CheckboxList
    {
        return CheckboxList::make('permissions')
            ->label('Neler yapabilir?')
            ->options(Project::PERMISSIONS)
            ->helperText('Projeyi ve verilerini (taramalar, Search Console, iş listesi) her üye görür. İşaretlenmeyenleri değiştiremez. Google hesabını ve ekibi yalnızca proje sahibi yönetir.')
            ->bulkToggleable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Davet edilenler yalnızca bu projeyi görür. Search Console verisi senin Google bağlantınla gelir; kendi Google hesaplarını bağlamaları gerekmez.')
            ->columns([
                TextColumn::make('name')->label('Ad'),
                TextColumn::make('email')->label('E-posta'),
                TextColumn::make('permissions')->label('Yetkiler')->badge()
                    ->state(fn (User $record): array => $record->pivot->permissions === null
                        ? [$record->canWrite() ? 'Rolüne göre: hepsi' : 'Salt okunur']
                        : ($record->pivot->permissions === [] ? ['Yalnızca görüntüler'] : array_map(fn ($p) => explode(' (', Project::PERMISSIONS[$p] ?? $p)[0], $record->pivot->permissions))),
            ])
            ->headerActions([
                Action::make('invite')
                    ->label('E-postayla davet et')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->modalHeading('Projeye davet et')
                    ->modalDescription('Kişi platformda yoksa hesap açma bağlantısı, varsa bu projeye katılma bağlantısı alır.')
                    ->schema([
                        TextInput::make('email')->label('E-posta')->email()->required(),
                        self::permissionsField()->default(Project::DEFAULT_PERMISSIONS),
                    ])
                    ->action(function (array $data): void {
                        $project = $this->getOwnerRecord();
                        if (Invitations::alreadyHasAccess($project, $data['email'])) {
                            Notification::make()->title('Bu kişi projeyi zaten görüyor')->body('Yetkilerini listedeki "Yetkiler" düğmesiyle değiştirebilirsin.')->warning()->send();

                            return;
                        }

                        $result = Invitations::send($project, $data['email'], $data['permissions'] ?? [], auth()->user());
                        $notification = $result['sent'] === true
                            ? Notification::make()->title('Davet gönderildi')->body($data['email'].' adresine e-posta gitti.')->success()
                            : Notification::make()->title('Davet hazır, e-posta gönderilemedi')
                                ->body(($result['sent'] === null ? 'Gönderen e-posta hesabı ayarlı değil (Bildirimler sayfası).' : 'E-posta sunucusu hata verdi.').' Bu bağlantıyı kişiye kendin ilet: '.$result['link'])
                                ->warning()->persistent()
                                ->actions([Action::make('copy')->label('Bağlantıyı kopyala')->alpineClickHandler('navigator.clipboard.writeText('.json_encode($result['link']).')')]);
                        $notification->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()->label('Yetkiler')->icon(Heroicon::OutlinedKey)
                    ->modalHeading(fn (User $record): string => $record->name.': yetkiler')
                    ->schema([self::permissionsField()])
                    ->mutateRecordDataUsing(fn (array $data, User $record): array => ['permissions' => $record->pivot->permissions ?? array_keys(Project::PERMISSIONS)] + $data)
                    ->using(function (User $record, array $data): User {
                        $this->getOwnerRecord()->members()->updateExistingPivot($record->id, ['permissions' => array_values($data['permissions'] ?? [])]);

                        return $record;
                    }),
                DetachAction::make()->label('Projeden çıkar')->modalDescription('Kişi bu projeyi artık göremez; hesabı ve diğer projeleri etkilenmez.'),
            ])
            ->emptyStateHeading('Bu projede başka kimse yok')
            ->emptyStateDescription('"E-postayla davet et" ile ekip arkadaşı ya da müşteri ekle.');
    }
}
