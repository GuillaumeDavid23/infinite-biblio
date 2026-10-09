<?php

namespace App\Ai\Provider;

/**
 * Remplace le fournisseur réel tant que AI_REAL_CALLS_ENABLED n'est pas activé :
 * toute tentative d'appel échoue sans contacter l'API.
 */
final class DisabledRealProvider implements TextGenerationProvider
{
    public function __construct(private readonly string $model)
    {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function countInputTokens(GenerationRequest $request): int
    {
        throw $this->refuse();
    }

    public function generate(GenerationRequest $request): GenerationResult
    {
        throw $this->refuse();
    }

    private function refuse(): ProviderException
    {
        return new ProviderException('Les appels payants sont désactivés (AI_REAL_CALLS_ENABLED=0). Aucun appel n\'a été envoyé.', false);
    }
}
