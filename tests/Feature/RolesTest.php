<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Crawl;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Monitoring\Channels;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role, array $projects = []): User
    {
        $user = User::factory()->create(['role' => $role, 'app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $user->projects()->attach(collect($projects)->pluck('id'));

        return $user;
    }

    public function test_members_only_see_their_projects(): void
    {
        $mine = Project::factory()->create(['name' => 'Benim']);
        $other = Project::factory()->create(['name' => 'Başkasının']);
        $crawl = Crawl::create(['project_id' => $mine->id, 'status' => 'completed', 'settings' => []]);
        $otherCrawl = Crawl::create(['project_id' => $other->id, 'status' => 'completed', 'settings' => []]);
        Task::create(['project_id' => $other->id, 'key' => 'rule:x', 'source' => 'audit', 'title' => 'Gizli iş', 'why' => 'x']);

        $specialist = $this->member('specialist', [$mine]);
        $this->actingAs($specialist);
        Filament::setCurrentPanel('admin');

        $this->assertSame([$mine->id], ProjectResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame([$crawl->id], CrawlResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame([], TaskResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame([$mine->client_id], ClientResource::getEloquentQuery()->pluck('id')->all());

        $this->get(CrawlResource::getUrl('view', ['record' => $crawl]))->assertOk();
        $this->get(CrawlResource::getUrl('view', ['record' => $otherCrawl]))->assertNotFound();

        $this->actingAs($this->member('admin'));
        $this->assertCount(2, ProjectResource::getEloquentQuery()->get());
    }

    public function test_roles_decide_who_can_change_things(): void
    {
        $project = Project::factory()->create();
        Filament::setCurrentPanel('admin');

        $this->actingAs($this->member('viewer', [$project]));
        $this->assertFalse(ProjectResource::canEdit($project));
        $this->assertFalse(ProjectResource::canCreate());

        $this->actingAs($this->member('specialist', [$project]));
        $this->assertTrue(ProjectResource::canEdit($project));
        $this->assertFalse(ProjectResource::canCreate(), 'only admins add projects');
        $this->assertFalse(ProjectResource::canDelete($project));

        $this->actingAs($this->member('admin'));
        $this->assertTrue(ProjectResource::canCreate());
        $this->assertTrue(ProjectResource::canDelete($project));
    }

    public function test_only_admins_manage_the_team_and_can_add_members(): void
    {
        Filament::setCurrentPanel('admin');
        $project = Project::factory()->create();

        $this->actingAs($this->member('specialist', [$project]))->get(UserResource::getUrl('index'))->assertForbidden();

        $this->actingAs($this->member('admin'));
        $this->get(UserResource::getUrl('index'))->assertOk();
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Ayşe', 'email' => 'ayse@example.com', 'password' => 'uzunbirsifre123', 'role' => 'specialist', 'projects' => [$project->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $ayse = User::where('email', 'ayse@example.com')->first();
        $this->assertSame('specialist', $ayse->role);
        $this->assertSame([$project->id], $ayse->projects()->pluck('projects.id')->all());
    }

    public function test_inactive_accounts_cannot_sign_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_alerts_only_reach_members_who_can_see_the_project(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::put('telegram_bot_token', '123:abc');
        $mine = Project::factory()->create();
        $other = Project::factory()->create();

        User::factory()->create(['role' => 'admin', 'telegram_chat_id' => '1']);
        User::factory()->create(['role' => 'specialist', 'telegram_chat_id' => '2'])->projects()->attach($mine->id);
        User::factory()->create(['role' => 'specialist', 'telegram_chat_id' => '3'])->projects()->attach($other->id);
        User::factory()->create(['role' => 'admin', 'telegram_chat_id' => '4', 'is_active' => false]);

        Channels::deliver('down', 'Erişilemiyor', 'x', $mine->id);

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]['chat_id'])->sort()->values()->all();
        $this->assertSame(['1', '2'], $sent);
    }
}
