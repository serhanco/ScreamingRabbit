<?php

namespace App\Filament\Resources\WatchedPages\Pages;

use App\Filament\Resources\WatchedPages\WatchedPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWatchedPage extends CreateRecord
{
    protected static string $resource = WatchedPageResource::class;
}
