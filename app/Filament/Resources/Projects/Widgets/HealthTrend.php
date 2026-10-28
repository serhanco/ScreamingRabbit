<?php

namespace App\Filament\Resources\Projects\Widgets;

use App\Models\ProjectScore;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

/**
 * Health score of the project's last crawls, oldest on the left, shown under the project form.
 */
class HealthTrend extends Widget
{
    protected string $view = 'projects.health-trend';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $points = ProjectScore::trend($this->record->id);

        return [
            'points' => $points,
            'change' => $points->count() >= 2 ? $points->last()['score'] - $points->first()['score'] : null,
        ];
    }
}
