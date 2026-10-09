<?php

namespace App\Twig;

use App\Ai\Pricing\Money;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('usd', static fn (?int $pico, int $decimals = 4): string => Money::format($pico, $decimals)),
            new TwigFilter('num', static fn (?int $n): string => null === $n ? '—' : number_format($n, 0, ',', ' ')),
            new TwigFilter('paragraphs', [self::class, 'paragraphs'], ['is_safe' => ['html']]),
            new TwigFilter('duration', static fn (?int $ms): string => null === $ms ? '—' : ($ms < 1000 ? $ms.' ms' : number_format($ms / 1000, 1, ',', ' ').' s')),
        ];
    }

    /** Affiche un texte en paragraphes, sans interpréter de HTML (le titre « # » initial est retiré). */
    public static function paragraphs(?string $text): string
    {
        $text = trim((string) preg_replace('/^\s*#[^\n]*\n/', '', (string) $text, 1));
        $out = '';
        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $para) {
            $para = trim($para);
            if ('' !== $para) {
                $out .= '<p>'.nl2br(htmlspecialchars($para, \ENT_QUOTES, 'UTF-8')).'</p>';
            }
        }

        return $out;
    }
}
