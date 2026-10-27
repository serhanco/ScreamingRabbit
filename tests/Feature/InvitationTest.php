<?php

namespace Tests\Feature;

use App\Filament\Resources\Crawls\Pages\ViewCrawl;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Crawl;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $serhan;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->serhan = User::factory()->create(['role' => 'admin', 'app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($this->serhan);
        $this->project = Project::factory()->create(['name' => 'Klinik']);
    }

    private function invite(string $email, array $permissions): string
    {
        Livewire::test(MembersRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->callTableAction('invite', data: ['email' => $email, 'permissions' => $permissions])
            ->assertHasNoTableActionErrors();

        // No mailbox is set up in tests, so the link is shown to the inviter instead; rebuild it from a fresh token.
        $invitation = Invitation::query()->latest('id')->firstOrFail();
        $token = str_repeat('t', 48).$invitation->id;
        $invitation->forceFill(['token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public function test_a_new_person_signs_up_through_the_link_and_only_sees_that_project(): void
    {
        $other = Project::factory()->create();
        $token = $this->invite('Yeni@Example.com', ['crawl']);
        $this->assertSame('yeni@example.com', Invitation::first()->email);

        Auth::logout();
        $this->get(route('invitation.show', $token))->assertOk()->assertSee('Klinik')->assertSee('Tarama başlatma')->assertSee('Hesabımı oluştur');
        $this->post(route('invitation.accept', $token), ['name' => 'Ayşe', 'password' => str_repeat('p', 4), 'password_confirmation' => str_repeat('p', 4)])->assertSessionHasErrors('password');
        $this->post(route('invitation.accept', $token), ['name' => 'Ayşe', 'password' => str_repeat('p', 12), 'password_confirmation' => str_repeat('p', 12)])
            ->assertRedirect(ProjectResource::getUrl('index'));

        $ayse = User::where('email', 'yeni@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($ayse);
        $this->assertSame('specialist', $ayse->role);
        $this->assertSame([$this->project->id], ProjectResource::getEloquentQuery()->pluck('id')->all());
        $this->assertFalse($ayse->canSeeProject($other->id));
        $this->assertTrue($ayse->canDo('crawl', $this->project->id));
        $this->assertFalse($ayse->canDo('tasks', $this->project->id));
        $this->assertFalse($ayse->canDo('settings', $this->project->id));

        $this->get(route('invitation.show', $token))->assertOk()->assertSee('geçerli değil');
        $this->post(route('invitation.accept', $token))->assertNotFound();
    }

    public function test_an_existing_member_signs_in_and_accepts(): void
    {
        $mehmet = User::factory()->create(['role' => 'specialist', 'email' => 'mehmet@example.com']);
        $token = $this->invite('mehmet@example.com', ['tasks', 'report']);

        Auth::logout();
        $this->get(route('invitation.show', $token))->assertOk()->assertSee('Giriş yap');
        $this->assertSame(route('invitation.show', $token), session('url.intended'));

        $this->actingAs(User::factory()->create(['role' => 'specialist']));
        $this->get(route('invitation.show', $token))->assertOk()->assertSee('başka bir hesapla');
        $this->post(route('invitation.accept', $token))->assertForbidden();

        $this->actingAs($mehmet);
        $this->get(route('invitation.show', $token))->assertOk()->assertSee('Daveti kabul et');
        $this->post(route('invitation.accept', $token))->assertRedirect();

        $this->assertTrue($mehmet->canDo('tasks', $this->project->id));
        $this->assertTrue($mehmet->canDo('report', $this->project->id));
        $this->assertFalse($mehmet->canDo('crawl', $this->project->id));
    }

    public function test_project_permissions_decide_what_a_member_can_change(): void
    {
        $crawl = Crawl::create(['project_id' => $this->project->id, 'status' => 'completed', 'settings' => []]);
        $task = Task::create(['project_id' => $this->project->id, 'key' => 'rule:x', 'source' => 'audit', 'title' => 'İş', 'why' => 'x']);
        $member = User::factory()->create(['role' => 'specialist', 'app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $member->projects()->attach($this->project->id, ['permissions' => ['report']]);

        $this->actingAs($member);
        $this->assertFalse(ProjectResource::canEdit($this->project), 'no settings permission');
        $this->assertFalse(TaskResource::canEdit($task), 'no tasks permission');
        $this->get(ProjectResource::getUrl('edit', ['record' => $this->project]))->assertForbidden();
        Livewire::test(ViewCrawl::class, ['record' => $crawl->getRouteKey()])
            ->assertActionVisible('share')
            ->assertActionHidden('recrawl');
        $this->assertFalse(MembersRelationManager::canViewForRecord($this->project, EditProject::class), 'members never manage the team');

        // Owner edits the permissions: now tasks too.
        $this->actingAs($this->serhan);
        Livewire::test(MembersRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->assertCanSeeTableRecords([$member])
            ->callTableAction('edit', $member, data: ['permissions' => ['report', 'tasks']])
            ->assertHasNoTableActionErrors();
        $this->assertTrue($member->fresh()->canDo('tasks', $this->project->id));

        // A viewer stays read-only whatever the pivot says.
        $viewer = User::factory()->create(['role' => 'viewer']);
        $viewer->projects()->attach($this->project->id, ['permissions' => ['crawl']]);
        $this->assertFalse($viewer->canDo('crawl', $this->project->id));

        // Older assignments without permissions keep their role-based rights.
        $legacy = User::factory()->create(['role' => 'specialist']);
        $legacy->projects()->attach($this->project->id);
        $this->assertTrue($legacy->canDo('settings', $this->project->id));
    }

    public function test_only_the_owner_or_primary_admin_can_invite(): void
    {
        $ali = User::factory()->create(['role' => 'admin', 'app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $ali->projects()->attach($this->project->id);
        $this->actingAs($ali);
        $this->assertFalse(MembersRelationManager::canViewForRecord($this->project, EditProject::class), 'assigned admin is not the owner');

        $alisProject = Project::factory()->create();
        $this->assertTrue(MembersRelationManager::canViewForRecord($alisProject, EditProject::class));

        $this->actingAs($this->serhan);
        Livewire::test(MembersRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => EditProject::class])
            ->callTableAction('invite', data: ['email' => $ali->email, 'permissions' => []]);
        $this->assertSame(0, Invitation::count(), 'already on the project');
    }

    public function test_viewer_invited_with_rights_becomes_specialist_but_keeps_other_projects_read_only(): void
    {
        $other = Project::factory()->create();
        $viewer = User::factory()->create(['role' => 'viewer', 'email' => 'izleyici@example.com']);
        $viewer->projects()->attach($other->id);
        $token = $this->invite('izleyici@example.com', ['crawl']);

        $this->actingAs($viewer)->post(route('invitation.accept', $token))->assertRedirect();

        $viewer->refresh();
        $this->assertSame('specialist', $viewer->role);
        $this->assertTrue($viewer->canDo('crawl', $this->project->id));
        $this->assertFalse($viewer->canDo('crawl', $other->id));
    }
}
