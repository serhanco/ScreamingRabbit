<?php

namespace App\Filament\Resources\WatchedPages\Pages;

use App\Filament\Resources\WatchedPages\WatchedPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWatchedPages extends ListRecords
{
    protected static string $resource = WatchedPageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
