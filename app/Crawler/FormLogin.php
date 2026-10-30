<?php

namespace App\Crawler;

use Closure;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Logs in through a site's login form before a crawl (docs/09 Faz 5), for members-only or staging sites that use a
 * form instead of HTTP basic auth. It opens the login page, fills in the form it finds there (hidden fields such as
 * nonces included), posts it and keeps the cookies the site sets. Everything stays on the project's own host.
 */
class FormLogin
{
    /** Field names login forms commonly use for the user name, WordPress first. */
    private const USER_FIELDS = ['log', 'user_login', 'username', 'user', 'email', 'login', 'user_email', 'kullanici'];

    /** While logged in, these addresses are never opened: logging out, the admin area and links that do something. */
    public const UNSAFE = '#/wp-admin|wp-login\.php|log-?out|sign-?out|/cikis|[?&](action|do)=#i';

    /**
     * @param  Closure(PendingRequest, string): PendingRequest  $withAccess  adds the project's basic auth and headers
     * @return array{cookie: string}|array{error: string}
     */
    public static function login(string $loginUrl, string $user, string $password, ?string $userField, string $baseUrl, string $userAgent, Closure $withAccess): array
    {
        if (! self::sameHost($loginUrl, $baseUrl)) {
            return ['error' => 'Giriş sayfası projenin alan adında olmalı.'];
        }

        $cookies = [];
        try {
            $page = $withAccess(Http::withUserAgent($userAgent)->timeout(20)->withoutRedirecting(), $loginUrl)->get($loginUrl);
        } catch (\Throwable) {
            return ['error' => 'Giriş sayfasına ulaşılamadı.'];
        }
        self::collect($page, $cookies);
        if (! $page->successful()) {
            return ['error' => "Giriş sayfası açılmadı (HTTP {$page->status()})."];
        }

        $form = self::form($page->body(), $loginUrl, $userField);
        if ($form === null) {
            return ['error' => 'Giriş sayfasında şifre alanı olan bir form bulunamadı.'];
        }
        if (! self::sameHost($form['action'], $baseUrl)) {
            return ['error' => 'Giriş formu başka bir alan adına gönderiliyor; güvenlik için giriş yapılmadı.'];
        }
        $fields = $form['fields'];
        $fields[$form['user']] = $user;
        $fields[$form['password']] = $password;

        $before = $cookies;
        try {
            $response = $withAccess(Http::withUserAgent($userAgent)->timeout(20)->withoutRedirecting()->asForm()
                ->withHeaders(['Cookie' => self::header($cookies), 'Referer' => $loginUrl]), $form['action'])->post($form['action'], $fields);
        } catch (\Throwable) {
            return ['error' => 'Giriş formu gönderilemedi.'];
        }
        self::collect($response, $cookies);

        $location = (string) $response->header('Location');
        $backToLogin = $location !== '' && strtok((string) UrlNormalizer::resolve($location, $form['action']), '?#') === strtok($loginUrl, '?#');
        $formAgain = $response->successful() && self::form($response->body(), $loginUrl, $userField) !== null;
        if ($response->clientError() || $response->serverError() || $backToLogin || $formAgain || $cookies === $before) {
            return ['error' => 'Giriş yapılamadı: kullanıcı adı veya şifre kabul edilmedi (ya da site ek doğrulama istiyor).'];
        }

        return ['cookie' => self::header($cookies)];
    }

    /**
     * The first form with a password field, its fields with their default values, and which fields take the user name
     * and the password.
     *
     * @return array{action: string, fields: array<string, string>, user: string, password: string}|null
     */
    public static function form(string $html, string $pageUrl, ?string $userField = null): ?array
    {
        if (trim($html) === '') {
            return null;
        }
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NONET);
        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//form[.//input[translate(@type, "PASSWORD", "password")="password"]]') as $form) {
            /** @var DOMElement $form */
            $fields = [];
            $password = null;
            $candidates = [];
            $submitted = false;
            foreach ($xpath->query('.//input|.//select|.//textarea', $form) as $input) {
                /** @var DOMElement $input */
                $name = $input->getAttribute('name');
                if ($name === '' || $input->hasAttribute('disabled')) {
                    continue;
                }
                $type = strtolower($input->getAttribute('type') ?: 'text');
                if ($input->nodeName === 'input' && $type === 'password') {
                    $password ??= $name;

                    continue;
                }
                if (in_array($type, ['submit', 'image', 'button'], true)) {
                    // Only the first submit button is sent, as a browser would.
                    if (! $submitted && $type === 'submit') {
                        $fields[$name] = $input->getAttribute('value');
                        $submitted = true;
                    }

                    continue;
                }
                if (in_array($type, ['checkbox', 'radio'], true) && ! $input->hasAttribute('checked')) {
                    continue;
                }
                if (in_array($type, ['file', 'reset'], true)) {
                    continue;
                }
                if ($input->nodeName === 'select') {
                    $selected = $xpath->query('.//option[@selected]', $input)->item(0) ?? $xpath->query('.//option', $input)->item(0);
                    $fields[$name] = $selected instanceof DOMElement ? ($selected->hasAttribute('value') ? $selected->getAttribute('value') : trim($selected->textContent)) : '';

                    continue;
                }
                $fields[$name] = $input->nodeName === 'textarea' ? $input->textContent : $input->getAttribute('value');
                if ($input->nodeName === 'input' && in_array($type, ['text', 'email'], true)) {
                    $candidates[] = $name;
                }
            }
            if ($password === null) {
                continue;
            }

            $user = filled($userField) ? $userField : null;
            foreach (self::USER_FIELDS as $known) {
                $user ??= in_array($known, $candidates, true) ? $known : null;
            }
            $user ??= $candidates[0] ?? null;
            if ($user === null) {
                continue;
            }
            unset($fields[$user]);

            $action = trim($form->getAttribute('action'));

            return ['action' => $action === '' ? $pageUrl : (UrlNormalizer::resolve($action, $pageUrl) ?? $pageUrl), 'fields' => $fields, 'user' => $user, 'password' => $password];
        }

        return null;
    }

    /** @param  array<string, string>  $cookies */
    private static function collect(Response $response, array &$cookies): void
    {
        foreach ($response->headers()['Set-Cookie'] ?? $response->headers()['set-cookie'] ?? [] as $line) {
            $pair = explode(';', $line, 2)[0];
            if (! str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode('=', $pair, 2));
            if ($name === '') {
                continue;
            }
            // A cookie set to expire is the site deleting it.
            if ($value === '' || $value === 'deleted' || preg_match('/max-age=0\b/i', $line)) {
                unset($cookies[$name]);
            } else {
                $cookies[$name] = $value;
            }
        }
    }

    /** @param  array<string, string>  $cookies */
    private static function header(array $cookies): string
    {
        return implode('; ', array_map(fn (string $name, string $value) => $name.'='.$value, array_keys($cookies), $cookies));
    }

    public static function sameHost(string $url, string $baseUrl): bool
    {
        $host = fn (string $u) => preg_replace('/^www\./', '', strtolower((string) parse_url($u, PHP_URL_HOST)));

        return preg_match('#^https?://#i', $url) === 1 && $host($url) !== '' && $host($url) === $host($baseUrl);
    }
}
