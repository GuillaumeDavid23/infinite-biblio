<?php

namespace App\Ai\Provider;

/**
 * Consommation telle que renvoyée par le fournisseur. Aucune valeur n'est déduite ni inventée :
 * un champ absent de la réponse reste null.
 */
final readonly class TokenUsage
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $cacheCreationInputTokens = null,
        public ?int $cacheCreation5mTokens = null,
        public ?int $cacheCreation1hTokens = null,
        public ?int $cacheReadInputTokens = null,
        public array $raw = [],
    ) {
    }

    public function isComplete(): bool
    {
        return null !== $this->inputTokens && null !== $this->outputTokens;
    }
}
