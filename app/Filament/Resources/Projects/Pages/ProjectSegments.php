<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Segments\Segments;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Site sections side by side: how big, how healthy and how much traffic each one has.
 */
class ProjectSegments extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'projects.segments';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Segmentler · '.$this->record->name;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $segments = new Segments($this->record);

        return $segments->all() + ['crawl' => $segments->crawl(), 'project' => $this->record];
    }
}
