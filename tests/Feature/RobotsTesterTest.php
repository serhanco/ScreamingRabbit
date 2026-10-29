<?php

namespace Tests\Feature;

use App\Crawler\RobotsTxt;
use App\Filament\Pages\RobotsTester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class RobotsTesterTest extends TestCase
{
    use RefreshDatabase;

    private const ROBOTS = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nUser-agent: GPTBot\nDisallow: /\n";

    public function test_the_deciding_rule_is_reported(): void
    {
        $robots = new RobotsTxt(self::ROBOTS, 'googlebot');
        $this->assertSame('*', $robots->group);
        $this->assertSame(['allowed' => false, 'rule' => 'Disallow: /wp-admin/'], $robots->decide('https://example.com/wp-admin/edit.php'));
        $this->assertSame(['allowed' => true, 'rule' => 'Allow: /wp-admin/admin-ajax.php'], $robots->decide('https://example.com/wp-admin/admin-ajax.php'));
        $this->assertSame(['allowed' => true, 'rule' => null], $robots->decide('https://example.com/blog/'));
        $this->assertSame('gptbot', (new RobotsTxt(self::ROBOTS, 'gptbot'))->group);
        $this->assertNull((new RobotsTxt('', 'gptbot'))->group);
    }

    public function test_urls_are_tested_for_each_bot(): void
    {
        Http::fake(['example.com/robots.txt' => Http::response(self::ROBOTS)]);
        $this->actingAs(User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));

        Livewire::test(RobotsTester::class)
            ->set('data.site', 'https://example.com/blog/')
            ->call('fetch')
            ->assertSet('data.robots', self::ROBOTS)
            ->set('data.urls', "/blog/\n/wp-admin/")
            ->set('data.bots', ['googlebot', 'gptbot'])
            ->call('test')
            ->assertHasNoErrors()
            ->assertSet('results.0.url', 'https://example.com/blog/')
            ->assertSet('results.0.bots.googlebot.allowed', true)
            ->assertSet('results.0.bots.gptbot.allowed', false)
            ->assertSet('results.1.bots.googlebot.rule', 'Disallow: /wp-admin/')
            ->assertSee('Engelli');
    }
}
