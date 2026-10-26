<?php

namespace Tests\Feature;

use App\Filament\Pages\NotificationSettings;
use App\Models\Setting;
use App\Models\User;
use App\Monitoring\Alerts;
use App\Monitoring\Channels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_secrets_are_stored_encrypted(): void
    {
        Setting::put('telegram_bot_token', '123:abc');

        $this->assertNotSame('123:abc', DB::table('settings')->where('key', 'telegram_bot_token')->value('value'));
        $this->assertSame('123:abc', Setting::get('telegram_bot_token'));
    }

    public function test_alerts_are_sent_to_telegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::put('telegram_bot_token', '123:abc');
        User::factory()->create(['telegram_chat_id' => '42']);
        User::factory()->create(['telegram_chat_id' => '43', 'alert_kinds' => ['ssl']]);

        Alerts::send('down', 'Erişilemiyor: Klinik', 'Site yanıt vermiyor.');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bot123:abc/sendMessage'
            && $request['chat_id'] === '42' && str_contains($request['text'], 'Erişilemiyor: Klinik'));
        Http::assertNotSent(fn ($request) => $request['chat_id'] === '43');
    }

    public function test_telegram_failure_keeps_telegrams_reason(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);
        Setting::put('telegram_bot_token', '123:abc');

        $this->assertFalse(Channels::telegram('999', 'x', 'y'));
        $this->assertSame('Bad Request: chat not found', Channels::$telegramError);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->assertTrue(Channels::telegram('42', 'x', 'y'));
        $this->assertNull(Channels::$telegramError);
    }

    public function test_unconfigured_channels_are_skipped(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->assertSame([$user->id => ['email' => null, 'telegram' => null]], Channels::deliver('down', 'x', 'y'));
        Http::assertNothingSent();
    }

    public function test_chat_id_is_found_from_the_latest_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => [['message' => ['chat' => ['id' => 777]]]]])]);

        $this->assertSame('777', Channels::findTelegramChat('123:abc'));
    }

    public function test_settings_page_saves(): void
    {
        $this->actingAs($user = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']));

        Livewire::test(NotificationSettings::class)
            ->assertSee('Bildirim ayarları')
            ->fillForm(['alert_email' => 'serhan@example.com', 'telegram_chat_id' => '42', 'alert_kinds' => ['down', 'up'], 'telegram_bot_token' => '123:abc'])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('serhan@example.com', $user->alert_email);
        $this->assertSame('42', $user->telegram_chat_id);
        $this->assertFalse($user->wantsAlert('slow'));
        $this->assertSame('123:abc', Setting::get('telegram_bot_token'));
        $this->assertSame(config('screamingrabbit.alerts.smtp_host'), Setting::get('smtp_host'));
    }

    public function test_heartbeat_pings_the_configured_url(): void
    {
        Http::fake();
        Setting::put('heartbeat_url', 'https://hc-ping.com/abc');

        Channels::heartbeat();

        Http::assertSent(fn ($request) => $request->url() === 'https://hc-ping.com/abc');
    }

    public function test_email_uses_the_mailbox_from_settings(): void
    {
        Mail::fake();
        Setting::put('smtp_username', 'bildirim@example.com');
        Setting::put('smtp_password', str_repeat('x', 8));

        $this->assertTrue(Channels::email('serhan@example.com', 'Başlık', 'Gövde'));
        $this->assertSame(config('screamingrabbit.alerts.smtp_host'), config('mail.mailers.alerts.host'));
        $this->assertSame('smtps', config('mail.mailers.alerts.scheme'));
        $this->assertSame(str_repeat('x', 8), config('mail.mailers.alerts.password'));
    }
}
