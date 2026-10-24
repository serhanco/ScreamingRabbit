<?php

namespace App\Filament\Resources\Clients\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Müşteri adı')
                    ->required()
                    ->maxLength(255),
                TextInput::make('contact_email')
                    ->label('İletişim e-postası')
                    ->email()
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label('Notlar')
                    ->columnSpanFull(),
            ]);
    }
}
