<?php

namespace Tests\Feature;

use App\Crawler\CrawlRunner;
use App\Crawler\FormLogin;
use App\Models\Crawl;
use App\Models\CrawlPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormLoginTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN_PAGE = <<<'HTML'
        <html><body>
        <form name="search" action="/ara"><input type="text" name="s"></form>
        <form name="loginform" action="https://uye.example.com/wp-login.php" method="post">
          <input type="text" name="log"><input type="password" name="pwd">
          <input type="checkbox" name="rememberme" value="forever">
          <input type="submit" name="wp-submit" value="Giriş">
          <input type="hidden" name="redirect_to" value="https://uye.example.com/wp-admin/">
          <input type="hidden" name="testcookie" value="1">
        </form></body></html>
        HTML;

    /** A WordPress-like members site: pages need the login cookie; the login needs the test cookie. */
    private function fakeSite(string $password): void
    {
        Http::fake(function (Request $request) use ($password) {
            $url = $request->url();
            $cookie = implode(';', $request->header('Cookie'));
            if (str_contains($url, 'wp-login.php') && $request->method() === 'GET') {
                return Http::response(self::LOGIN_PAGE, 200, ['Content-Type' => 'text/html', 'Set-Cookie' => 'wordpress_test_cookie=WP%20Cookie%20check; path=/']);
            }
            if (str_contains($url, 'wp-login.php')) {
                $ok = str_contains($cookie, 'wordpress_test_cookie') && $request['log'] === 'tarayici' && $request['pwd'] === $password && $request['testcookie'] === '1';

                return $ok
                    ? Http::response('', 302, ['Location' => 'https://uye.example.com/wp-admin/', 'Set-Cookie' => ['wordpress_logged_in_abc=tarayici%7Ctoken; path=/; HttpOnly']])
                    : Http::response(self::LOGIN_PAGE, 200, ['Content-Type' => 'text/html']);
            }
            if (! str_contains($url, 'uye.example.com')) {
                return Http::response('', 404);
            }
            if (! str_contains($cookie, 'wordpress_logged_in_abc')) {
                return Http::response('<html><body>Lütfen giriş yapın</body></html>', 200, ['Content-Type' => 'text/html']);
            }
            if (str_ends_with($url, 'uye.example.com/')) {
                return Http::response('<html><body><a href="/dersler/">Dersler</a><a href="/wp-login.php?action=logout&_wpnonce=1">Çıkış</a><a href="/wp-admin/post.php?post=1&action=trash">Sil</a><a href="https://baska.com/">Dış</a></body></html>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('<html><head><title>Dersler</title></head><body>Üyelere özel</body></html>', 200, ['Content-Type' => 'text/html']);
        });
    }

    private function crawl(string $password): Crawl
    {
        $project = Project::factory()->create(['base_url' => 'https://uye.example.com', 'crawl_settings' => ['delay_ms' => 0],
            'crawl_auth' => ['login_url' => 'https://uye.example.com/wp-login.php', 'login_user' => 'tarayici', 'login_password' => $password]]);
        $crawl = Crawl::create(['project_id' => $project->id, 'status' => 'pending', 'settings' => $project->effectiveCrawlSettings()]);
        while ((new CrawlRunner($crawl->fresh()))->run()) {
        }

        return $crawl->fresh();
    }

    public function test_the_crawl_logs_in_never_logs_out_and_keeps_the_session_to_its_own_host(): void
    {
        $password = Str::random(16);
        $this->fakeSite($password);

        $crawl = $this->crawl($password);

        $this->assertSame('completed', $crawl->status);
        $this->assertStringContainsString('Giriş yapıldı', $crawl->login_result);
        $this->assertSame('Dersler', CrawlPage::where('url', 'https://uye.example.com/dersler/')->value('title'));
        $this->assertSame(0, CrawlPage::where('url', 'like', '%logout%')->orWhere('url', 'like', '%wp-admin%')->count(), 'no logout or admin links');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'logout') || str_contains($request->url(), 'action=trash'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'baska.com') && $request->hasHeader('Cookie'));
        $this->assertNull(DB::table('crawls')->value('session_cookie'), 'the session is dropped when the crawl ends');
    }

    public function test_a_wrong_password_is_reported_and_the_crawl_goes_on_logged_out(): void
    {
        $this->fakeSite(Str::random(16));

        $crawl = $this->crawl(Str::random(16));

        $this->assertSame('completed', $crawl->status);
        $this->assertStringContainsString('kabul edilmedi', $crawl->login_result);
        $this->assertNull(CrawlPage::where('url', 'https://uye.example.com/dersler/')->first(), 'logged out, the members page is not linked');
    }

    public function test_forms_posting_to_another_site_are_refused(): void
    {
        $this->assertNull(FormLogin::form('<form><input name="q"></form>', 'https://a.com/'));
        $form = FormLogin::form(self::LOGIN_PAGE, 'https://uye.example.com/wp-login.php');
        $this->assertSame(['https://uye.example.com/wp-login.php', 'log', 'pwd'], [$form['action'], $form['user'], $form['password']]);
        $this->assertSame(['wp-submit' => 'Giriş', 'redirect_to' => 'https://uye.example.com/wp-admin/', 'testcookie' => '1'], $form['fields'], 'unchecked boxes are left out');

        Http::fake(fn () => Http::response(str_replace('https://uye.example.com/wp-login.php', 'https://kotu.com/topla', self::LOGIN_PAGE), 200));
        $result = FormLogin::login('https://uye.example.com/wp-login.php', 'u', 'p', null, 'https://uye.example.com', 'ua', fn ($request) => $request);
        $this->assertStringContainsString('başka bir alan adına', $result['error']);
        Http::assertSentCount(1);

        $this->assertSame(['error' => 'Giriş sayfası projenin alan adında olmalı.'], FormLogin::login('https://kotu.com/login', 'u', 'p', null, 'https://uye.example.com', 'ua', fn ($request) => $request));
    }
}
