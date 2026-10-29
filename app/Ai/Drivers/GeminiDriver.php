<?php

namespace App\Ai\Drivers;

use App\Ai\AiException;
use App\Ai\Driver;
use Illuminate\Support\Facades\Http;

/** Google Gemini through the Generative Language API. */
class GeminiDriver implements Driver
{
    public function __construct(private readonly string $apiKey) {}

    public function complete(string $model, string $system, string $prompt, int $maxTokens, string $tier, array $images = []): array
    {
        $parts = array_map(fn (array $image) => ['inlineData' => ['mimeType' => $image['mime'], 'data' => $image['data']]], $images);
        $parts[] = ['text' => $prompt];

        try {
            $response = Http::timeout(120)->acceptJson()->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => $parts]],
                    'generationConfig' => ['maxOutputTokens' => $maxTokens],
                ]);
        } catch (\Throwable) {
            throw new AiException('Gemini\'ye bağlanılamadı.');
        }

        if (! $response->successful()) {
            throw new AiException(match ($response->status()) {
                400, 401, 403 => 'Gemini isteği reddetti: '.mb_substr((string) $response->json('error.message'), 0, 200),
                429 => 'Gemini istek sınırına takıldı; biraz sonra yeniden denenecek.',
                default => "Gemini hata verdi (HTTP {$response->status()}).",
            });
        }

        $text = collect($response->json('candidates.0.content.parts') ?? [])->pluck('text')->filter()->implode('');

        return [
            'text' => trim($text),
            'input_tokens' => (int) $response->json('usageMetadata.promptTokenCount'),
            'output_tokens' => (int) $response->json('usageMetadata.candidatesTokenCount'),
            'model' => $model,
        ];
    }
}
