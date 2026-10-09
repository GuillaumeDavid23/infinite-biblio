<?php

namespace App\Ai\Pricing;

use App\Ai\Provider\TokenUsage;

/**
 * Calcule les coûts en picodollars (entiers) à partir de la consommation renvoyée par l'API.
 */
final class CostCalculator
{
    /**
     * Coût constaté. Renvoie null si la consommation est incomplète : on n'invente rien.
     */
    public function actualCost(TokenUsage $usage, PriceEntry $price): ?int
    {
        if (!$usage->isComplete()) {
            return null;
        }

        $cost = $usage->inputTokens * $price->inputMicro
            + $usage->outputTokens * $price->outputMicro
            + ($usage->cacheReadInputTokens ?? 0) * $price->cacheReadMicro;

        $creation = $usage->cacheCreationInputTokens ?? 0;
        $c5m = $usage->cacheCreation5mTokens;
        $c1h = $usage->cacheCreation1hTokens;
        if (null !== $c5m && null !== $c1h && ($c5m + $c1h) === $creation) {
            $cost += $c5m * $price->cacheWrite5mMicro + $c1h * $price->cacheWrite1hMicro;
        } else {
            // Détail par durée absent : l'application n'utilise que le cache 5 minutes.
            $cost += $creation * $price->cacheWrite5mMicro;
        }

        return $cost;
    }

    /**
     * Plafond avant appel : tokens d'entrée mesurés (facturés au pire au tarif d'écriture en cache)
     * + totalité de la sortie maximale autorisée. C'est une borne haute, pas une prévision.
     */
    public function ceiling(int $inputTokens, int $maxOutputTokens, PriceEntry $price): int
    {
        return $inputTokens * max($price->inputMicro, $price->cacheWrite5mMicro)
            + $maxOutputTokens * $price->outputMicro;
    }
}
