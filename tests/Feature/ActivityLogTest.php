<?php

namespace Tests\Feature;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_changes_are_logged_and_background_work_is_not(): void
    {
        $project = Project::factory()->create(['name' => 'Klinik']);
        $task = Task::create(['project_id' => $project->id, 'key' => 'rule:x', 'source' => 'audit', 'title' => 'Title eksik', 'why' => 'x']);
        $this->assertSame(0, ActivityLog::count(), 'no member signed in: not logged');

        $admin = User::factory()->create(['name' => 'Serhan', 'app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($admin);

        $task = $task->fresh();
        $task->update(['status' => 'doing', 'score' => 99]);
        $entry = ActivityLog::latest('id')->first();
        $this->assertSame(['updated', $project->id, $admin->id], [$entry->action, $entry->project_id, $entry->user_id]);
        $this->assertSame(['status' => ['old' => 'open', 'new' => 'doing']], $entry->changes, 'only the columns that matter');

        $task->update(['score' => 5]);
        $this->assertSame(1, ActivityLog::where('subject_type', 'Task')->count(), 'a change of an unlogged column alone writes nothing');

        // Secrets: the change is recorded, never the value; saving the same value again is not a change.
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_bot_token', '123:abc');
        $settings = ActivityLog::where('subject_type', 'Setting')->get();
        $this->assertCount(1, $settings);
        $this->assertStringNotContainsString('123:abc', json_encode($settings));

        Filament::setCurrentPanel('admin');
        Livewire::test(ListActivityLogs::class)->assertSee('İş: Title eksik')->assertSee('Durum: open → doing');
    }

    public function test_members_only_see_their_projects_log(): void
    {
        $mine = Project::factory()->create();
        $other = Project::factory()->create();
        $admin = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($admin);
        $mine->update(['name' => 'Benim proje']);
        $other->update(['name' => 'Başkası']);
        Setting::put('heartbeat_url', 'https://hc-ping.com/x');

        $member = User::factory()->create(['role' => 'specialist', 'app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $member->projects()->attach($mine->id);
        $this->actingAs($member);
        Filament::setCurrentPanel('admin');

        $this->assertSame([$mine->id], ActivityLogResource::getEloquentQuery()->pluck('project_id')->unique()->values()->all());
    }
}
