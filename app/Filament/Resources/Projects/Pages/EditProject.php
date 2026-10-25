<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Actions\StartCrawlAction;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Support\Icons\Heroicon;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StartCrawlAction::make(),
            Action::make('insights')
                ->label('Search Console fırsatları')
                ->icon(Heroicon::OutlinedLightBulb)
                ->color('gray')
                ->visible(fn (): bool => filled($this->record->gsc_property))
                ->url(fn (): string => ProjectResource::getUrl('insights', ['record' => $this->record])),
            DeleteAction::make(),
        ];
    }
}
