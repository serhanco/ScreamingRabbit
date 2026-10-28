<?php

namespace App\Filament\Resources\AlertEvents\Pages;

use App\Filament\Resources\AlertEvents\AlertEventResource;
use Filament\Resources\Pages\ListRecords;

class ListAlertEvents extends ListRecords
{
    protected static string $resource = AlertEventResource::class;
}
