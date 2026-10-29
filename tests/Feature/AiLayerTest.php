<?php

namespace Tests\Feature;

use App\Ai\Ai;
use App\Ai\AiException;
use App\Ai\Driver;
use App\Filament\Pages\AiSettings;
use App\Models\AiRequest;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class AiLayerTest extends TestCase
{
    use RefreshDatabase;

    /** A driver that answers from a script and remembers what it was asked. */
    private function fakeDriver(string $provider, array $script): object
    {
        $driver = new class($script) implements Driver
        {
            public array $calls = [];

            public function __construct(public array $script) {}

            public function complete(string $model, string $system, string $prompt, int $maxTokens, string $tier, array $images = []): array
            {
                $this->calls[] = compact('model', 'tier', 'prompt');
                $next = array_shift($this->script);
                if ($next instanceof AiException) {
                    throw $next;
                }

                return $next;
            }
        };
        $this->app->instance("ai.driver.{$provider}", $driver);

        return $driver;
    }

    public function test_a_task_uses_the_cheap_model_logs_the_cost_and_reuses_the_answer(): void
    {
        Setting::put('ai_key_anthropic', 'sk-test');
        $project = Project::factory()->create();
        $driver = $this->fakeDriver('anthropic', [['text' => 'Yeni başlık', 'input_tokens' => 1_000_000, 'output_tokens' => 200_000, 'model' => 'claude-haiku-5-5'], ['text' => 'Yorum', 'input_tokens' => 10, 'output_tokens' => 10, 'model' => 'claude-opus-5-5']]);

        $first = Ai::ask('title_meta', 'Sistem', 'Başlık öner', $project->id);
        $this->assertSame('Yeni başlık', $first->text);
        $this->assertFalse($first->cached);
        $this->assertSame('claude-haiku-5-5', $driver->calls[0]['model']);
        $this->assertEqualsWithDelta(0.10 + 0.10, $first->cost, 0.000001, '1M input at 0.10 + 0.2M output at 0.50');
        $this->assertEqualsWithDelta(0.20, Ai::monthSpend(), 0.000001);

        $again = Ai::ask('title_meta', 'Sistem', 'Başlık öner', $project->id);
        $this->assertTrue($again->cached);
        $this->assertSame('Yeni başlık', $again->text);
        $this->assertCount(1, $driver->calls, 'the cached answer costs nothing');

        Ai::ask('quality', 'Sistem', 'Başlık öner');
        $this->assertSame('claude-opus-5-5', $driver->calls[1]['model'] ?? null, 'quality uses the strong model');
        $this->assertNotEquals('sk-test', Setting::query()->whereKey('ai_key_anthropic')->value('value'), 'keys are stored encrypted');
        $this->assertSame('sk-test', Crypt::decryptString(Setting::query()->whereKey('ai_key_anthropic')->value('value')));
    }

    public function test_a_failing_provider_falls_back_and_a_refusal_does_not(): void
    {
        Setting::put('ai_key_anthropic', 'sk-test');
        Setting::put('ai_key_mistral', 'mk-test');
        Setting::put('ai_model_mistral_cheap', 'mistral-small-latest');
        Setting::put('ai_model_mistral_strong', 'mistral-large-latest');
        Setting::put('ai_default_provider', 'anthropic');
        Setting::put('ai_fallback_provider', 'mistral');

        $this->fakeDriver('anthropic', [new AiException('Anthropic: hız sınırı'), new AiException('Model bu isteği reddetti.', tryFallback: false)]);
        $mistral = $this->fakeDriver('mistral', [['text' => 'Yedek cevap', 'input_tokens' => 10, 'output_tokens' => 5, 'model' => 'mistral-small-latest']]);

        $result = Ai::ask('alt_text', 'Sistem', 'Alt metin');
        $this->assertSame('mistral', $result->provider);
        $this->assertSame('Yedek cevap', $result->text);
        $this->assertSame(['error', 'ok'], AiRequest::orderBy('id')->pluck('status')->all());

        try {
            Ai::ask('alt_text', 'Sistem', 'Başka soru');
            $this->fail('a refusal stops');
        } catch (AiException $e) {
            $this->assertStringContainsString('reddetti', $e->getMessage());
        }
        $this->assertCount(1, $mistral->calls);
    }

    public function test_the_monthly_budget_stops_new_requests(): void
    {
        Setting::put('ai_key_anthropic', 'sk-test');
        Setting::put('ai_monthly_budget', '1');
        AiRequest::create(['task' => 'quality', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'cache_key' => str_repeat('a', 64), 'cost_usd' => 1.2, 'status' => 'ok', 'created_at' => now()]);
        $driver = $this->fakeDriver('anthropic', []);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('bütçesi');
        try {
            Ai::ask('quality', 'Sistem', 'Soru');
        } finally {
            $this->assertSame([], $driver->calls);
        }
    }

    public function test_nothing_is_sent_without_a_provider(): void
    {
        $this->expectException(AiException::class);
        Ai::ask('title_meta', 'Sistem', 'Soru');
    }

    public function test_the_primary_admin_saves_providers_and_tests_the_connection(): void
    {
        $admin = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($admin);
        $this->fakeDriver('anthropic', [['text' => 'OK', 'input_tokens' => 5, 'output_tokens' => 1, 'model' => 'claude-haiku-5-5']]);

        Livewire::test(AiSettings::class)
            ->assertFormSet(['ai_model_anthropic_cheap' => 'claude-haiku-5-5', 'ai_model_anthropic_strong' => 'claude-opus-5-5'])
            ->fillForm(['ai_key_anthropic' => ' sk-ant-test ', 'ai_monthly_budget' => '25', 'ai_task_tier_alt_text' => 'strong'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->callAction('test', ['provider' => 'anthropic'])
            ->assertNotified('Bağlantı çalışıyor');

        $this->assertSame('sk-ant-test', Setting::get('ai_key_anthropic'));
        $this->assertSame('25', Setting::get('ai_monthly_budget'));
        $this->assertSame('strong', Setting::get('ai_task_tier_alt_text'));
        $this->assertTrue(Ai::configured('anthropic'));
        $this->assertFalse(Ai::configured('openai'));

        $second = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($second)->get(AiSettings::getUrl())->assertForbidden();
    }
}
