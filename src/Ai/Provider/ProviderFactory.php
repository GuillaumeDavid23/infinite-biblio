<?php

namespace App\Ai\Provider;

final class ProviderFactory
{
    public static function create(
        string $provider,
        bool $realCallsEnabled,
        #[\SensitiveParameter] string $apiKey,
        string $model,
        string $effort,
        float $timeoutSeconds,
        int $simulatedDelayMs,
    ): TextGenerationProvider {
        return match ($provider) {
            'simulation', 'simulated', '' => new SimulatedProvider($model, $simulatedDelayMs),
            'anthropic' => $realCallsEnabled
                ? new AnthropicProvider($apiKey, $model, $effort, $timeoutSeconds)
                : new DisabledRealProvider($model),
            default => throw new \InvalidArgumentException(\sprintf('AI_PROVIDER inconnu : « %s » (valeurs : simulation, anthropic).', $provider)),
        };
    }
}
