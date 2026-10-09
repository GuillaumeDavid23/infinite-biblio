<?php

namespace App\Generation;

/**
 * Échec d'une étape. « retryable » indique qu'une nouvelle tentative automatique a un sens.
 */
final class StepFailedException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
