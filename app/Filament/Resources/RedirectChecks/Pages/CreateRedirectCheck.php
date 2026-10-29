<?php

namespace App\Filament\Resources\RedirectChecks\Pages;

use App\Filament\Resources\RedirectChecks\RedirectCheckResource;
use App\Models\RedirectCheck;
use Filament\Resources\Pages\CreateRecord;

class CreateRedirectCheck extends CreateRecord
{
    protected static string $resource = RedirectCheckResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_unless(auth()->user()->canDo('crawl', (int) $data['project_id']), 403);

        return [
            'project_id' => $data['project_id'],
            'name' => $data['name'],
            'user_id' => auth()->id(),
            'rows' => RedirectCheck::parseList($data['list']),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
