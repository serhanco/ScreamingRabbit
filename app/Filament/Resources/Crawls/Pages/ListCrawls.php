<?php

namespace App\Filament\Resources\Crawls\Pages;

use App\Filament\Resources\Crawls\CrawlResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCrawls extends ListRecords
{
    protected static string $resource = CrawlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
