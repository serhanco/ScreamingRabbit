<?php

namespace App\Filament\Resources\RedirectChecks\Pages;

use App\Filament\Resources\RedirectChecks\RedirectCheckResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRedirectChecks extends ListRecords
{
    protected static string $resource = RedirectCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Yeni kontrol')];
    }
}
