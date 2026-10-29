<?php

namespace Tests\Feature;

use App\Crawler\RedirectChecker;
use App\Filament\Resources\RedirectChecks\Pages\CreateRedirectCheck;
use App\Filament\Resources\RedirectChecks\RedirectCheckResource;
use App\Models\Project;
use App\Models\RedirectCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class RedirectCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_pasted_lists_are_parsed(): void
    {
        $rows = RedirectCheck::parseList("https://eski.com/a\thttps://yeni.com/a\n\nhttps://eski.com/b, https://yeni.com/b\nnot a url\nhttps://eski.com/a\nhttps://eski.com/c");
        $this->assertSame([
            ['url' => 'https://eski.com/a', 'expected' => 'https://yeni.com/a', 'state' => 'pending'],
            ['url' => 'https://eski.com/b', 'expected' => 'https://yeni.com/b', 'state' => 'pending'],
            ['url' => 'https://eski.com/c', 'expected' => null, 'state' => 'pending'],
        ], $rows);
    }

    public function test_old_urls_are_followed_and_judged(): void
    {
        Http::fake([
            'eski.com/ok' => Http::response('', 301, ['Location' => 'https://yeni.com/ok']),
            'eski.com/chain' => Http::response('', 301, ['Location' => 'http://yeni.com/chain']),
            'yeni.com/chain' => Http::sequence()->push('', 301, ['Location' => 'https://yeni.com/chain-final']),
            'eski.com/temp' => Http::response('', 302, ['Location' => '/temp-new']),
            'eski.com/temp-new' => Http::response('ok'),
            'eski.com/wrong' => Http::response('', 301, ['Location' => 'https://yeni.com/']),
            'eski.com/gone' => Http::response('', 301, ['Location' => 'https://yeni.com/yok']),
            'yeni.com/yok' => Http::response('', 404),
            'eski.com/loop' => Http::response('', 301, ['Location' => 'https://eski.com/loop']),
            'eski.com/same' => Http::response('ok'),
            '*' => Http::response('ok'),
        ]);

        $user = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $project = Project::factory()->create();
        $this->actingAs($user);
        Livewire::test(CreateRedirectCheck::class)
            ->fillForm(['project_id' => $project->id, 'name' => 'Yeni site', 'list' => implode("\n", [
                'https://eski.com/ok https://yeni.com/ok', 'https://eski.com/chain', 'https://eski.com/temp', 'https://eski.com/wrong https://yeni.com/wrong-page',
                'https://eski.com/gone', 'https://eski.com/loop', 'https://eski.com/same',
            ])])
            ->call('create')
            ->assertHasNoFormErrors();

        $check = RedirectCheck::firstOrFail();
        $this->assertSame($user->id, $check->user_id);
        RedirectChecker::runDue();

        $verdicts = collect($check->fresh()->rows)->pluck('verdict', 'url')->all();
        $this->assertSame([
            'https://eski.com/ok' => 'ok',
            'https://eski.com/chain' => 'chain',
            'https://eski.com/temp' => 'temporary',
            'https://eski.com/wrong' => 'wrong_target',
            'https://eski.com/gone' => 'broken',
            'https://eski.com/loop' => 'loop',
            'https://eski.com/same' => 'same',
        ], $verdicts);
        $this->assertSame('done', $check->fresh()->status);

        $this->get(RedirectCheckResource::getUrl('view', ['record' => $check]))->assertOk()
            ->assertSee('Zincirleme yönlendirme')->assertSee('301 → 301');
    }
}
