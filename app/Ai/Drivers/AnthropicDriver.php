<?php

namespace App\Ai\Drivers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Ai\AiException;
use App\Ai\Driver;

/** Claude through the official Anthropic PHP SDK. */
class AnthropicDriver implements Driver
{
    /** Models that accept the server-side refusal fallback (`fallbacks: "default"`). */
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];

    /** Models that take output_config.effort. */
    private const EFFORT_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'];

    public function __construct(private readonly string $apiKey) {}

    public function complete(string $model, string $system, string $prompt, int $maxTokens, string $tier, array $images = []): array
    {
        $content = $prompt;
        if ($images !== []) {
            $content = array_map(fn (array $image) => ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $image['mime'], 'data' => $image['data']]], $images);
            $content[] = ['type' => 'text', 'text' => $prompt];
        }
        $client = new Client(apiKey: $this->apiKey, requestOptions: ['timeout' => 120, 'maxRetries' => 2]);
        $params = [
            'model' => $model,
            'maxTokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $content]],
        ];
        if (in_array($model, self::EFFORT_MODELS, true)) {
            $params['outputConfig'] = ['effort' => $tier === 'cheap' ? 'low' : 'medium'];
        }

        try {
            $message = in_array($model, self::FALLBACK_MODELS, true)
                ? $client->beta->messages->create(...$params, betas: ['server-side-fallback-2026-07-01'], fallbacks: 'default')
                : $client->messages->create(...$params);
        } catch (AuthenticationException) {
            throw new AiException('Anthropic API anahtarı geçersiz.');
        } catch (RateLimitException) {
            throw new AiException('Anthropic istek sınırına takıldı; biraz sonra yeniden denenecek.');
        } catch (APIConnectionException) {
            throw new AiException('Anthropic\'e bağlanılamadı.');
        } catch (APIStatusException $e) {
            throw new AiException('Anthropic hata verdi: '.mb_substr($e->getMessage(), 0, 200));
        } catch (APIException $e) {
            throw new AiException('Anthropic isteği tamamlanamadı: '.mb_substr($e->getMessage(), 0, 200));
        }

        if ($message->stopReason === 'refusal') {
            throw new AiException('Model bu isteği yanıtlamayı reddetti.', tryFallback: false);
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return ['text' => trim($text), 'input_tokens' => $message->usage->inputTokens, 'output_tokens' => $message->usage->outputTokens, 'model' => (string) $message->model];
    }
}
