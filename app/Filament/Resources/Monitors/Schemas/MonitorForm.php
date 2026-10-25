<?php

namespace App\Filament\Resources\Monitors\Schemas;

use App\Models\Monitor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MonitorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('İzleme hedefi')
                    ->columns(2)
                    ->schema([
                        Select::make('project_id')
                            ->label('Proje')
                            ->relationship('project', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('url')
                            ->label('Adres')
                            ->url()
                            ->required()
                            ->maxLength(500)
                            ->helperText('WordPress sitelerde /wp-json/ adresi veritabanı hatasını da yakalar.'),
                        Select::make('interval_minutes')
                            ->label('Kontrol sıklığı')
                            ->options(Monitor::INTERVALS)
                            ->default(1)
                            ->required(),
                        TextInput::make('keyword')
                            ->label('Sayfada olması gereken kelime')
                            ->maxLength(255)
                            ->helperText('İsteğe bağlı. "Hesap askıya alındı" gibi 200 dönen hata sayfalarını yakalar.'),
                        Toggle::make('is_active')
                            ->label('Etkin')
                            ->default(true),
                    ]),
            ]);
    }
}
