<?php

namespace App\Ai\Pricing;

/**
 * Tarif daté d'un modèle. Les taux sont stockés en micro-dollars par million de tokens,
 * ce qui équivaut à des picodollars par token : coût (pico$) = tokens × taux.
 */
final readonly class PriceEntry
{
    public function __construct(
        public string $model,
        public \DateTimeImmutable $effectiveFrom,
        public string $source,
        public int $inputMicro,
        public int $outputMicro,
        public int $cacheWrite5mMicro,
        public int $cacheWrite1hMicro,
        public int $cacheReadMicro,
    ) {
    }

    public function reference(): string
    {
        return $this->model.'@'.$this->effectiveFrom->format('Y-m-d');
    }

    /**
     * Convertit « 2.50 » ($/million de tokens) en 2500000 sans passer par un flottant.
     */
    public static function parseRate(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', $value, $m)) {
            throw new \InvalidArgumentException(\sprintf('Tarif invalide : « %s » (format attendu : 2.50).', $value));
        }

        return (int) $m[1] * 1_000_000 + (int) str_pad($m[2] ?? '', 6, '0');
    }
}
