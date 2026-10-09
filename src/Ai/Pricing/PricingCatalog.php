<?php

namespace App\Ai\Pricing;

use Symfony\Component\Yaml\Yaml;

/**
 * Grille tarifaire datée et configurable (config/ai/pricing.yaml).
 */
final class PricingCatalog
{
    /** @var list<PriceEntry>|null */
    private ?array $entries = null;

    public function __construct(private readonly string $pricingFile)
    {
    }

    public function find(string $model, ?\DateTimeImmutable $at = null): ?PriceEntry
    {
        $at ??= new \DateTimeImmutable();
        $best = null;
        foreach ($this->all() as $entry) {
            if ($entry->model !== $model || $entry->effectiveFrom > $at) {
                continue;
            }
            if (null === $best || $entry->effectiveFrom > $best->effectiveFrom) {
                $best = $entry;
            }
        }

        return $best;
    }

    /** @return list<PriceEntry> */
    public function all(): array
    {
        if (null !== $this->entries) {
            return $this->entries;
        }
        $data = Yaml::parseFile($this->pricingFile);
        $entries = [];
        foreach ($data['prices'] ?? [] as $row) {
            $entries[] = new PriceEntry(
                (string) $row['model'],
                new \DateTimeImmutable((string) $row['effective_from']),
                (string) ($row['source'] ?? ''),
                PriceEntry::parseRate((string) $row['input']),
                PriceEntry::parseRate((string) $row['output']),
                PriceEntry::parseRate((string) $row['cache_write_5m']),
                PriceEntry::parseRate((string) $row['cache_write_1h']),
                PriceEntry::parseRate((string) $row['cache_read']),
            );
        }

        return $this->entries = $entries;
    }
}
