<?php

namespace App\Filament\Resources\WatchedPages\Pages;

use App\Filament\Resources\WatchedPages\WatchedPageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWatchedPage extends EditRecord
{
    protected static string $resource = WatchedPageResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
