<?php

namespace App\Ai;

final class AiResult
{
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly float $cost,
        public readonly bool $cached,
    ) {}
}
