<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use App\Models\Crawl;
use App\Models\Project;
use App\Reports\MonthlyReports;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListReports extends ListRecords
{
    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // For a report before the monthly run, or again after a new crawl: replaces this month's unapproved draft.
            Action::make('make')
                ->label('Bu ayın raporunu şimdi hazırla')
                ->visible(fn (): bool => auth()->user()->canWrite())
                ->schema([
                    Select::make('project_id')->label('Proje')->required()->searchable()
                        ->options(fn (): array => Project::query()->visibleTo(auth()->user())->orderBy('name')->get()
                            ->filter(fn (Project $project) => auth()->user()->canDo('report', $project->id))->pluck('name', 'id')->all()),
                ])
                ->action(function (array $data): void {
                    $project = Project::query()->visibleTo(auth()->user())->findOrFail($data['project_id']);
                    abort_unless(auth()->user()->canDo('report', $project->id), 403);
                    $crawl = Crawl::query()->where('project_id', $project->id)->where('status', 'completed')->latest('id')->first();
                    if (! $crawl) {
                        Notification::make()->title('Bu projenin tamamlanmış bir taraması yok')->warning()->send();

                        return;
                    }
                    $existing = $project->reports()->where('period', now()->format('Y-m'))->first();
                    if ($existing && $existing->status !== 'draft') {
                        Notification::make()->title('Bu ayın raporu zaten onaylanmış')->warning()->send();

                        return;
                    }
                    MonthlyReports::make($project, $crawl, now()->format('Y-m'));
                    Notification::make()->title('Rapor hazırlandı, onayını bekliyor')->success()->send();
                }),
        ];
    }
}
