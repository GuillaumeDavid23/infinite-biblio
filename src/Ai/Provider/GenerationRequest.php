<?php

namespace App\Ai\Provider;

use App\Enum\Step;

final readonly class GenerationRequest
{
    /**
     * @param array<string, mixed>|null $jsonSchema         schéma de sortie structurée (null = texte libre)
     * @param array<string, mixed>      $simulationContext données utilisées uniquement par le fournisseur simulé
     */
    public function __construct(
        public Step $step,
        public string $system,
        public string $userPrompt,
        public int $maxOutputTokens,
        public ?array $jsonSchema = null,
        public array $simulationContext = [],
    ) {
    }
}
