<?php

namespace App\Twig;

use App\Ai\Provider\TextGenerationProvider;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/** Expose aux pages le mode d'IA actif (simulation ou appels réels). */
final class AiModeExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private readonly TextGenerationProvider $provider)
    {
    }

    public function getGlobals(): array
    {
        return ['ai_mode' => [
            'simulated' => $this->provider->isSimulated(),
            'provider' => $this->provider->name(),
            'model' => $this->provider->model(),
        ]];
    }
}
