<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientsAndProjectsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));
    }

    public function test_clients_are_listed(): void
    {
        $clients = Client::factory()->count(2)->create();

        Livewire::test(ListClients::class)
            ->assertCanSeeTableRecords($clients);
    }

    public function test_a_client_can_be_created(): void
    {
        Livewire::test(CreateClient::class)
            ->fillForm(['name' => 'Hekim Dijital', 'contact_email' => 'info@example.com'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['name' => 'Hekim Dijital']);
    }

    public function test_a_client_requires_a_name(): void
    {
        Livewire::test(CreateClient::class)
            ->fillForm(['name' => ''])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);
    }

    public function test_a_project_can_be_created_and_defaults_apply(): void
    {
        $client = Client::factory()->create();

        Livewire::test(CreateProject::class)
            ->fillForm([
                'client_id' => $client->id,
                'name' => 'Ana site',
                'base_url' => 'https://ornek.com',
                'platform' => 'wordpress',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::firstWhere('name', 'Ana site');

        $this->assertNotNull($project);
        $this->assertSame(config('screamingrabbit.crawl_defaults.concurrency'), $project->effectiveCrawlSettings()['concurrency']);
    }

    public function test_project_settings_override_defaults(): void
    {
        $project = Project::factory()->create(['crawl_settings' => ['concurrency' => 1]]);

        $settings = $project->effectiveCrawlSettings();

        $this->assertSame(1, $settings['concurrency']);
        $this->assertSame(config('screamingrabbit.crawl_defaults.delay_ms'), $settings['delay_ms']);
    }

    public function test_project_requires_a_valid_url(): void
    {
        $client = Client::factory()->create();

        Livewire::test(CreateProject::class)
            ->fillForm(['client_id' => $client->id, 'name' => 'X', 'base_url' => 'not-a-url', 'platform' => 'wordpress'])
            ->call('create')
            ->assertHasFormErrors(['base_url']);
    }

    public function test_blank_settings_fall_back_but_false_is_kept(): void
    {
        $project = Project::factory()->create(['crawl_settings' => ['concurrency' => null, 'delay_ms' => '', 'respect_robots' => false]]);

        $settings = $project->effectiveCrawlSettings();

        $this->assertSame(config('screamingrabbit.crawl_defaults.concurrency'), $settings['concurrency']);
        $this->assertSame(config('screamingrabbit.crawl_defaults.delay_ms'), $settings['delay_ms']);
        $this->assertFalse($settings['respect_robots']);
    }
}
