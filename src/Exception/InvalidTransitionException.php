<?php

namespace App\Exception;

final class InvalidTransitionException extends \DomainException
{
    public static function for(string $subject, string $from, string $to): self
    {
        return new self(\sprintf('Changement de statut interdit pour %s : « %s » vers « %s ».', $subject, $from, $to));
    }
}
