<?php

namespace App\Jobs;

use App\Ai\TitleMeta;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** One page of a bulk AI title/meta run; each page is its own short job so shared hosting time limits are never hit. */
class ProposeTitleMeta implements ShouldQueue
{
    use Queueable;

    public int $timeout = 55;

    public int $tries = 1;

    public function __construct(public int $projectId, public string $url, public int $userId) {}

    public function handle(): void
    {
        $project = Project::find($this->projectId);
        $user = User::find($this->userId);
        if ($project === null || $user === null) {
            return;
        }
        $result = TitleMeta::propose($project, $this->url, $user);
        if (is_string($result)) {
            Log::info('AI title/meta suggestion skipped', ['url' => $this->url, 'reason' => $result]);
        }
    }
}
