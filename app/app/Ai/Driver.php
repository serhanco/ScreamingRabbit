<?php

namespace App\Ai;

interface Driver
{
    /**
     * @return array{text: string, input_tokens: int, output_tokens: int, model: string}
     *
     * @throws AiException
     */
    public function complete(string $model, string $system, string $prompt, int $maxTokens, string $tier): array;
}
