<?php

namespace App\Ai;

/** An AI call that did not produce an answer; the message is shown to the user as is (Turkish). */
class AiException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $tryFallback = true)
    {
        parent::__construct($message);
    }
}
