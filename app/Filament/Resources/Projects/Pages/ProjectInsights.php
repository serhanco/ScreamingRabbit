<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Google\GscInsights;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * What Search Console data says to work on for one project.
 */
class ProjectInsights extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'projects.insights';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Search Console fırsatları · '.$this->record->name;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['insights' => (new GscInsights($this->record))->all(), 'project' => $this->record];
    }
}
