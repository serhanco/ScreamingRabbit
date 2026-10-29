<?php

namespace App\Ai;

interface Driver
{
    /**
     * @param  list<array{mime: string, data: string}>  $images  base64 images shown to the model with the prompt
     * @return array{text: string, input_tokens: int, output_tokens: int, model: string}
     *
     * @throws AiException
     */
    public function complete(string $model, string $system, string $prompt, int $maxTokens, string $tier, array $images = []): array;
}
