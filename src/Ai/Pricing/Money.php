<?php

namespace App\Ai\Pricing;

final class Money
{
    public const PICO_PER_DOLLAR = 1_000_000_000_000;

    public static function fromDollars(string $dollars): int
    {
        $dollars = str_replace(',', '.', trim($dollars));
        if (!preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', $dollars, $m)) {
            throw new \InvalidArgumentException('Montant invalide.');
        }

        return (int) $m[1] * self::PICO_PER_DOLLAR + (int) str_pad($m[2] ?? '', 6, '0') * 1_000_000;
    }

    public static function format(?int $pico, int $decimals = 4): string
    {
        if (null === $pico) {
            return 'inconnu';
        }

        return number_format($pico / self::PICO_PER_DOLLAR, $decimals, ',', ' ').' $';
    }
}
