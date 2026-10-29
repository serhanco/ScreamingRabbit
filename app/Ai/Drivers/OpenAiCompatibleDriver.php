<?php

namespace App\Ai\Drivers;

use App\Ai\AiException;
use App\Ai\Driver;
use Illuminate\Support\Facades\Http;

/** OpenAI's chat completions API, which Mistral, OpenRouter and local servers (Ollama, LM Studio) also speak. */
class OpenAiCompatibleDriver implements Driver
{
    public function __construct(private readonly string $label, private readonly string $baseUrl, private readonly ?string $apiKey) {}

    public function complete(string $model, string $system, string $prompt, int $maxTokens, string $tier): array
    {
        try {
            $response = Http::timeout(120)->acceptJson()->withToken((string) $this->apiKey)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (\Throwable $e) {
            throw new AiException("{$this->label}'e bağlanılamadı.");
        }

        if (! $response->successful()) {
            throw new AiException(match ($response->status()) {
                401, 403 => "{$this->label} API anahtarı geçersiz.",
                429 => "{$this->label} istek sınırına takıldı; biraz sonra yeniden denenecek.",
                default => "{$this->label} hata verdi (HTTP {$response->status()}): ".mb_substr((string) $response->json('error.message'), 0, 200),
            });
        }

        return [
            'text' => trim((string) $response->json('choices.0.message.content')),
            'input_tokens' => (int) $response->json('usage.prompt_tokens'),
            'output_tokens' => (int) $response->json('usage.completion_tokens'),
            'model' => (string) ($response->json('model') ?: $model),
        ];
    }
}
