<?php

namespace App\Ai;

use App\Ai\Drivers\AnthropicDriver;
use App\Ai\Drivers\GeminiDriver;
use App\Ai\Drivers\OpenAiCompatibleDriver;
use App\Models\AiRequest;
use App\Models\Setting;

/**
 * The AI layer (docs/09 Faz 7, AI-0): several providers, a cheap and a strong model per provider, a provider chosen per
 * task with a fallback, a monthly spending limit and an answer cache. Keys live encrypted in the app's settings, never
 * in the WordPress plugin. Everything the AI writes is a suggestion that a person approves before it is used.
 */
class Ai
{
    public const PROVIDERS = [
        'anthropic' => 'Anthropic (Claude)',
        'openai' => 'OpenAI',
        'gemini' => 'Google Gemini',
        'mistral' => 'Mistral',
        'compatible' => 'OpenAI uyumlu uç nokta (OpenRouter, yerel model)',
    ];

    public const TIERS = ['cheap' => 'Ekonomik model', 'strong' => 'Güçlü model'];

    /** Tasks and the model tier each uses unless the settings say otherwise. */
    public const TASKS = [
        'title_meta' => ['Title/meta önerisi', 'cheap'],
        'alt_text' => ['Alt metin önerisi', 'cheap'],
        'quality' => ['Kalite yorumu (title, şema, içerik, GEO/AEO)', 'strong'],
        'report_summary' => ['Müşteri raporu özeti', 'strong'],
        'internal_links' => ['İç link önerisi', 'cheap'],
    ];

    /** Anthropic's current models and list prices (USD per million tokens, input/output), filled in as defaults. */
    public const ANTHROPIC_DEFAULTS = [
        'cheap' => ['model' => 'claude-haiku-5-5', 'in' => 0.10, 'out' => 0.50],
        'strong' => ['model' => 'claude-opus-5-5', 'in' => 4.00, 'out' => 20.00],
    ];

    /** Known per-model prices, used when a fallback model answered instead of the configured one. */
    private const KNOWN_PRICES = [
        'claude-opus-5-5' => [4.00, 20.00], 'claude-opus-5' => [5.00, 25.00], 'claude-opus-4-8' => [5.00, 25.00],
        'claude-sonnet-5-5' => [2.00, 10.00], 'claude-haiku-5-5' => [0.10, 0.50], 'claude-fable-5-1' => [10.00, 50.00],
    ];

    public const MISTRAL_URL = 'https://api.mistral.ai/v1';

    public const OPENAI_URL = 'https://api.openai.com/v1';

    /** A cached answer is reused for this many days. */
    private const CACHE_DAYS = 30;

    /**
     * Answer for $task; the cached answer when the same question was asked before.
     *
     * @param  list<array{mime: string, data: string}>  $images
     */
    public static function ask(string $task, string $system, string $prompt, ?int $projectId = null, int $maxTokens = 2000, array $images = []): AiResult
    {
        $tier = Setting::get("ai_task_tier_{$task}") ?: (self::TASKS[$task][1] ?? 'cheap');
        $providers = array_values(array_filter(
            array_unique(array_filter([Setting::get("ai_task_provider_{$task}") ?: Setting::get('ai_default_provider'), Setting::get('ai_fallback_provider')])),
            fn (string $provider) => self::configured($provider),
        ));
        // Nothing chosen yet: the first provider with a key.
        $providers = $providers ?: array_slice(array_values(array_filter(array_keys(self::PROVIDERS), fn (string $provider) => self::configured($provider))), 0, 1);
        if ($providers === []) {
            throw new AiException('AI sağlayıcısı ayarlanmamış: Ayarlar → AI sağlayıcıları sayfasında bir API anahtarı girin.', tryFallback: false);
        }

        $errors = [];
        foreach ($providers as $provider) {
            $model = self::model($provider, $tier);
            $key = hash('sha256', implode("\n", [$provider, $model, $maxTokens, $system, $prompt, ...array_map(fn (array $image) => hash('sha256', $image['data']), $images)]));
            $cached = AiRequest::where('cache_key', $key)->where('status', 'ok')->where('created_at', '>=', now()->subDays(self::CACHE_DAYS))->latest('id')->first();
            if ($cached !== null) {
                return new AiResult((string) $cached->response, $provider, $cached->model, 0.0, true, $cached->id);
            }
            self::checkBudget();

            try {
                $answer = self::driver($provider)->complete($model, $system, $prompt, $maxTokens, $tier, $images);
            } catch (AiException $e) {
                self::log($task, $provider, $model, $projectId, $key, 'error', error: $e->getMessage());
                $errors[] = $e->getMessage();
                if (! $e->tryFallback) {
                    break;
                }

                continue;
            }

            $cost = self::cost($provider, $tier, $answer['model'], $answer['input_tokens'], $answer['output_tokens']);
            $request = self::log($task, $provider, $answer['model'], $projectId, $key, 'ok', $answer, $cost);

            return new AiResult($answer['text'], $provider, $answer['model'], $cost, false, $request->id);
        }

        throw new AiException(implode(' ', array_unique($errors)), tryFallback: false);
    }

    /** An answer that turned out unusable is not served from the cache again (its cost stays counted). */
    public static function discard(AiResult $result): void
    {
        AiRequest::whereKey($result->requestId)->update(['status' => 'unusable']);
    }

    public static function configured(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]) && ($provider === 'compatible' ? filled(Setting::get('ai_url_compatible')) : filled(Setting::get("ai_key_{$provider}")))
            && filled(self::model($provider, 'cheap')) && filled(self::model($provider, 'strong'));
    }

    public static function model(string $provider, string $tier): string
    {
        return (string) (Setting::get("ai_model_{$provider}_{$tier}") ?: ($provider === 'anthropic' ? self::ANTHROPIC_DEFAULTS[$tier]['model'] : ''));
    }

    /** Spending this calendar month (UTC), in USD. */
    public static function monthSpend(): float
    {
        return (float) AiRequest::where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');
    }

    public static function driver(string $provider): Driver
    {
        if (app()->bound("ai.driver.{$provider}")) {
            return app("ai.driver.{$provider}");
        }

        return match ($provider) {
            'anthropic' => new AnthropicDriver((string) Setting::get('ai_key_anthropic')),
            'gemini' => new GeminiDriver((string) Setting::get('ai_key_gemini')),
            'openai' => new OpenAiCompatibleDriver('OpenAI', self::OPENAI_URL, Setting::get('ai_key_openai')),
            'mistral' => new OpenAiCompatibleDriver('Mistral', self::MISTRAL_URL, Setting::get('ai_key_mistral')),
            default => new OpenAiCompatibleDriver('OpenAI uyumlu uç nokta', (string) Setting::get('ai_url_compatible'), Setting::get('ai_key_compatible')),
        };
    }

    private static function checkBudget(): void
    {
        $budget = (float) Setting::get('ai_monthly_budget', 0);
        if ($budget > 0 && self::monthSpend() >= $budget) {
            throw new AiException('Bu ayın AI bütçesi ('.number_format($budget, 2).' $) doldu. Ayarlar → AI sağlayıcıları sayfasından artırabilirsiniz.', tryFallback: false);
        }
    }

    private static function cost(string $provider, string $tier, string $model, int $in, int $out): float
    {
        $prices = self::KNOWN_PRICES[$model] ?? null;
        if ($prices === null || $model === self::model($provider, $tier)) {
            $default = $provider === 'anthropic' ? self::ANTHROPIC_DEFAULTS[$tier] : ['in' => 0, 'out' => 0];
            $prices = [(float) Setting::get("ai_price_{$provider}_{$tier}_in", $default['in']), (float) Setting::get("ai_price_{$provider}_{$tier}_out", $default['out'])];
        }

        return round(($in * $prices[0] + $out * $prices[1]) / 1_000_000, 6);
    }

    /** @param  array{text?: string, input_tokens?: int, output_tokens?: int}  $answer */
    private static function log(string $task, string $provider, string $model, ?int $projectId, string $key, string $status, array $answer = [], float $cost = 0, ?string $error = null): AiRequest
    {
        return AiRequest::create([
            'task' => $task, 'provider' => $provider, 'model' => mb_substr($model, 0, 100), 'project_id' => $projectId, 'user_id' => auth()->id(),
            'cache_key' => $key, 'input_tokens' => $answer['input_tokens'] ?? 0, 'output_tokens' => $answer['output_tokens'] ?? 0, 'cost_usd' => $cost,
            'status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 500), 'response' => $answer['text'] ?? null, 'created_at' => now(),
        ]);
    }
}
