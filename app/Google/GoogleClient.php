<?php

namespace App\Google;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Talks to Google Search Console with one Google account connected from the panel (OAuth, offline access).
 * The OAuth client id/secret and the refresh token live in the settings table, the secret parts encrypted.
 * Only the panel user who connected the account (the owner) may see its Search Console property list.
 */
class GoogleClient
{
    public const SCOPES = ['openid', 'email', 'https://www.googleapis.com/auth/webmasters.readonly'];

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/webmasters/v3';

    public static function configured(): bool
    {
        return filled(Setting::get('google_client_id')) && filled(Setting::get('google_client_secret'));
    }

    public static function connected(): bool
    {
        return self::configured() && filled(Setting::get('google_refresh_token'));
    }

    /** The connected account is private to the user who connected it; before that, any admin may set it up. */
    public static function canManage(?User $user): bool
    {
        if ($user === null || ! $user->isAdmin()) {
            return false;
        }

        return ! self::connected() || self::isOwner($user);
    }

    public static function isOwner(?User $user): bool
    {
        return $user !== null && self::connected() && (string) Setting::get('google_owner_id') === (string) $user->getKey();
    }

    public static function redirectUri(): string
    {
        return route('filament.admin.google.callback');
    }

    public static function authUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => Setting::get('google_client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            // Offline access with a forced consent screen so Google always returns a refresh token.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /** Swaps the code from the consent screen for tokens and remembers the account. */
    public static function connect(string $code, User $owner): void
    {
        $tokens = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => Setting::get('google_client_id'),
            'client_secret' => Setting::get('google_client_secret'),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        $email = Http::withToken($tokens['access_token'])->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');

        Setting::put('google_refresh_token', $tokens['refresh_token'] ?? null);
        Setting::put('google_email', $email);
        Setting::put('google_owner_id', $owner->getKey());
        Cache::forget('google_sites');
        Cache::put('google_access_token', $tokens['access_token'], now()->addSeconds(max(60, ($tokens['expires_in'] ?? 3600) - 300)));
    }

    public static function disconnect(): void
    {
        Setting::put('google_refresh_token', null);
        Setting::put('google_email', null);
        Setting::put('google_owner_id', null);
        Cache::forget('google_access_token');
        Cache::forget('google_sites');
    }

    public static function accessToken(): string
    {
        return Cache::remember('google_access_token', now()->addMinutes(50), fn () => Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'refresh_token' => Setting::get('google_refresh_token'),
            'client_id' => Setting::get('google_client_id'),
            'client_secret' => Setting::get('google_client_secret'),
            'grant_type' => 'refresh_token',
        ])->throw()->json('access_token'));
    }

    /**
     * Search Console properties the connected account can read.
     *
     * @return array<string, string> property => label
     */
    public static function sites(): array
    {
        if (! self::connected()) {
            return [];
        }

        return Cache::remember('google_sites', now()->addMinutes(10), fn () => collect(
            Http::withToken(self::accessToken())->timeout(15)->get(self::API.'/sites')->throw()->json('siteEntry') ?? []
        )
            ->reject(fn ($site) => $site['permissionLevel'] === 'siteUnverifiedUser')
            ->mapWithKeys(fn ($site) => [$site['siteUrl'] => str_starts_with($site['siteUrl'], 'sc-domain:') ? substr($site['siteUrl'], 10).' (alan adı)' : $site['siteUrl']])
            ->sort()
            ->all());
    }

    /**
     * Google's view of one URL: indexStatusResult of the URL Inspection API.
     *
     * @return array<string, mixed>
     */
    public static function inspectUrl(string $property, string $url): array
    {
        return Http::withToken(self::accessToken())->timeout(30)
            ->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
                'inspectionUrl' => $url,
                'siteUrl' => $property,
                'languageCode' => 'tr',
            ])
            ->throw()->json('inspectionResult.indexStatusResult') ?? [];
    }

    /**
     * One page of Search Analytics rows.
     *
     * @param  array<string, mixed>  $body
     * @return array<int, array<string, mixed>>
     */
    public static function searchAnalytics(string $property, array $body): array
    {
        return Http::withToken(self::accessToken())->timeout(30)
            ->post(self::API.'/sites/'.rawurlencode($property).'/searchAnalytics/query', $body)
            ->throw()->json('rows') ?? [];
    }
}
