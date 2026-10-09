<?php

namespace App\Ai\Provider;

/**
 * Échec d'appel au fournisseur (réseau, quota, erreur API). Le message ne doit jamais contenir de clé.
 */
final class ProviderException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
