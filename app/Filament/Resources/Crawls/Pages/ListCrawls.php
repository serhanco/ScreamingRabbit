<?php

namespace App\Filament\Resources\Crawls\Pages;

use App\Crawler\CrawlRunner;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCrawls extends ListRecords
{
    protected static string $resource = CrawlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')
                ->label('Yeni tarama')
                ->icon(Heroicon::OutlinedPlay)
                ->visible(fn (): bool => $this->projects() !== [])
                ->modalHeading('Yeni tarama')
                ->modalDescription('Site, proje ayarlarındaki sınırlara uyularak taranır. Sonuçlar dakikalar içinde dolmaya başlar.')
                ->modalSubmitActionLabel('Taramayı başlat')
                ->schema([Select::make('project_id')->label('Proje')->options(fn (): array => $this->projects())->required()->searchable()])
                ->action(function (array $data): void {
                    $project = Project::find($data['project_id']);
                    if ($project === null || ! auth()->user()->canDo('crawl', $project->id)) {
                        return;
                    }
                    $active = $project->crawls()->whereIn('status', ['pending', 'running'])->first();
                    if ($active) {
                        Notification::make()->title('Bu proje için bir tarama zaten sürüyor')->warning()->send();
                    } else {
                        Notification::make()->title('Tarama başlatıldı')->success()->send();
                    }
                    $this->redirect(CrawlResource::getUrl('view', ['record' => $active ?? CrawlRunner::start($project)]));
                }),
        ];
    }

    /** @return array<int, string> projects the user may crawl */
    private function projects(): array
    {
        return Project::query()->visibleTo(auth()->user())->orderBy('name')->get()
            ->filter(fn (Project $project) => auth()->user()->canDo('crawl', $project->id))
            ->pluck('name', 'id')->all();
    }
}
