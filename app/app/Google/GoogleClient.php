<?php

namespace App\Google;

use App\Models\GoogleConnection;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Talks to Google Search Console. The OAuth client id/secret (one Google Cloud app, set up by the primary admin) live
 * in the settings table; each admin connects their own Google account and its refresh token sits encrypted in
 * google_connections. A connection's Search Console site list is only ever shown to the member who made it, and a
 * project reads its data with the connection of the member who picked its property.
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

    /** The user's own connection, if they have connected Google. */
    public static function connectionOf(?User $user): ?GoogleConnection
    {
        return $user === null ? null : GoogleConnection::query()->where('user_id', $user->getKey())->first();
    }

    public static function connected(?User $user): bool
    {
        return self::configured() && self::connectionOf($user) !== null;
    }

    /** Admins connect their own Google account; invited members use the project owner's connection instead. */
    public static function canConnect(?User $user): bool
    {
        return $user !== null && $user->isAdmin() && self::configured();
    }

    /** The OAuth client is shared by everyone, so only the primary admin sets or changes it. */
    public static function canManageClient(?User $user): bool
    {
        return $user?->isPrimary() ?? false;
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

    /** Swaps the code from the consent screen for tokens and stores them as the user's own connection. */
    public static function connect(string $code, User $user): GoogleConnection
    {
        $tokens = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => Setting::get('google_client_id'),
            'client_secret' => Setting::get('google_client_secret'),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        $email = Http::withToken($tokens['access_token'])->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');

        $connection = GoogleConnection::query()->updateOrCreate(['user_id' => $user->getKey()], ['email' => $email, 'refresh_token' => $tokens['refresh_token']]);
        Cache::forget("google_sites:{$connection->id}");
        Cache::put("google_access_token:{$connection->id}", $tokens['access_token'], now()->addSeconds(max(60, ($tokens['expires_in'] ?? 3600) - 300)));

        return $connection;
    }

    public static function disconnect(GoogleConnection $connection): void
    {
        Cache::forget("google_access_token:{$connection->id}");
        Cache::forget("google_sites:{$connection->id}");
        $connection->delete();
    }

    public static function accessToken(GoogleConnection $connection): string
    {
        return Cache::remember("google_access_token:{$connection->id}", now()->addMinutes(50), fn () => Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'refresh_token' => $connection->refresh_token,
            'client_id' => Setting::get('google_client_id'),
            'client_secret' => Setting::get('google_client_secret'),
            'grant_type' => 'refresh_token',
        ])->throw()->json('access_token'));
    }

    /**
     * Search Console properties the user's own connection can read. Never call this for anyone but that user.
     *
     * @return array<string, string> property => label
     */
    public static function sites(?User $user): array
    {
        $connection = self::configured() ? self::connectionOf($user) : null;
        if ($connection === null) {
            return [];
        }

        return Cache::remember("google_sites:{$connection->id}", now()->addMinutes(10), fn () => collect(
            Http::withToken(self::accessToken($connection))->timeout(15)->get(self::API.'/sites')->throw()->json('siteEntry') ?? []
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
    public static function inspectUrl(GoogleConnection $connection, string $property, string $url): array
    {
        $connection->countCall(inspection: true);

        return Http::withToken(self::accessToken($connection))->timeout(30)
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
    public static function searchAnalytics(GoogleConnection $connection, string $property, array $body): array
    {
        $connection->countCall();

        return Http::withToken(self::accessToken($connection))->timeout(30)
            ->post(self::API.'/sites/'.rawurlencode($property).'/searchAnalytics/query', $body)
            ->throw()->json('rows') ?? [];
    }
}
