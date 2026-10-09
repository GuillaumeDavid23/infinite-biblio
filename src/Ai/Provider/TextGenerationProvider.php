<?php

namespace App\Ai\Provider;

/**
 * Point d'extension pour brancher une autre IA plus tard.
 * Une seule intégration réelle existe aujourd'hui (Anthropic), plus un simulateur.
 */
interface TextGenerationProvider
{
    /** Identifiant court du fournisseur (ex. « anthropic », « simulation »). */
    public function name(): string;

    /** Les résultats sont-ils simulés (aucun appel payant) ? */
    public function isSimulated(): bool;

    /** Modèle demandé, utilisé aussi pour retrouver la grille tarifaire. */
    public function model(): string;

    /**
     * Nombre de tokens d'entrée de la requête, mesuré par le fournisseur (pas une conversion de mots).
     *
     * @throws ProviderException
     */
    public function countInputTokens(GenerationRequest $request): int;

    /**
     * @throws ProviderException
     */
    public function generate(GenerationRequest $request): GenerationResult;
}
