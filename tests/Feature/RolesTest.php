<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Crawls\CrawlResource;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Client;
use App\Models\Crawl;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Monitoring\Channels;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

    public function test_a_second_admin_only_sees_projects_they_own_or_are_assigned_to(): void
    {
        Filament::setCurrentPanel('admin');
        $serhan = $this->member('admin');
        $this->assertTrue($serhan->fresh()->isPrimary(), 'the first admin is the primary admin');
        $this->actingAs($serhan);
        $serhansProject = Project::factory()->create(['name' => 'Serhan']);
        $shared = Project::factory()->create(['name' => 'Ortak']);

        $ali = $this->member('admin', [$shared]);
        $this->assertFalse($ali->isPrimary());
        $this->actingAs($ali);
        Livewire::test(CreateProject::class)
            ->fillForm(['client_id' => Client::create(['name' => 'Ali müşteri'])->id, 'name' => 'Ali', 'base_url' => 'https://ali.com', 'platform' => 'generic'])
            ->call('create')
            ->assertHasNoFormErrors();
        $alisProject = Project::where('name', 'Ali')->first();
        $this->assertSame($ali->id, $alisProject->owner_id);

        $this->assertEqualsCanonicalizing([$shared->id, $alisProject->id], ProjectResource::getEloquentQuery()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$shared->client_id, $alisProject->client_id], ClientResource::getEloquentQuery()->pluck('id')->all());
        $this->get(ProjectResource::getUrl('edit', ['record' => $serhansProject]))->assertNotFound();
        $this->assertFalse($ali->canSeeProject($serhansProject->id));
        $this->assertTrue(ProjectResource::canDelete($alisProject));
        $this->assertFalse(ProjectResource::canDelete($shared), 'only the owner or the primary admin deletes');
        $this->get(UserResource::getUrl('index'))->assertForbidden();

        $this->actingAs($serhan);
        $this->assertCount(3, ProjectResource::getEloquentQuery()->get());
        $this->assertTrue(ProjectResource::canDelete($alisProject));
    }

    public function test_alerts_for_a_second_admin_follow_their_projects(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::put('telegram_bot_token', '123:abc');
        $this->actingAs(User::factory()->create(['role' => 'admin', 'telegram_chat_id' => '1']));
        $other = Project::factory()->create();
        $ali = User::factory()->create(['role' => 'admin', 'telegram_chat_id' => '2']);
        $this->actingAs($ali);
        $alis = Project::factory()->create();

        Channels::deliver('down', 'Erişilemiyor', 'x', $other->id);
        $this->assertSame(['1'], collect(Http::recorded())->map(fn ($pair) => $pair[0]['chat_id'])->all());

        Channels::deliver('down', 'Erişilemiyor', 'x', $alis->id);
        $this->assertSame(['1', '1', '2'], collect(Http::recorded())->map(fn ($pair) => $pair[0]['chat_id'])->sort()->values()->all());
    }

    public function test_migration_moves_the_old_google_connection_to_its_owner(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1]);
        $first = DB::table('users')->insertGetId(['name' => 'İlk', 'email' => 'ilk@example.com', 'password' => 'x', 'role' => 'admin']);
        $serhan = DB::table('users')->insertGetId(['name' => 'Serhan', 'email' => 's@example.com', 'password' => 'x', 'role' => 'admin']);
        $client = DB::table('clients')->insertGetId(['name' => 'Müşteri']);
        $project = DB::table('projects')->insertGetId(['client_id' => $client, 'name' => 'P', 'base_url' => 'https://p.com', 'platform' => 'generic', 'gsc_property' => 'sc-domain:p.com']);
        DB::table('settings')->insert([
            ['key' => 'google_refresh_token', 'value' => Crypt::encryptString('refresh-old')],
            ['key' => 'google_email', 'value' => 'serhan@example.com'],
            ['key' => 'google_owner_id', 'value' => (string) $serhan],
        ]);

        $this->artisan('migrate');

        $this->assertTrue(User::find($serhan)->isPrimary(), 'the Google owner becomes the primary admin');
        $this->assertFalse(User::find($first)->isPrimary());
        $this->assertSame('refresh-old', User::find($serhan)->googleConnection->refresh_token);
        $this->assertSame('serhan@example.com', User::find($serhan)->googleConnection->email);
        $this->assertSame($serhan, Project::find($project)->gsc_user_id);
        $this->assertSame($serhan, Project::find($project)->owner_id);
        $this->assertSame($serhan, Client::find($client)->owner_id);
        $this->assertNull(Setting::get('google_owner_id'));
    }
}
