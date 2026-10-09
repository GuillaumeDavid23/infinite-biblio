<?php

namespace App\Model;

/**
 * Convertit le formulaire de modification du plan (champs simples, une ligne par élément) en structure de plan.
 */
final class PlanForm
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     *
     * @return array<string, mixed>
     */
    public static function fromRequest(array $input, array $current, int $chapterCount): array
    {
        $plan = $current;
        foreach (['reformulation', 'synopsis', 'stakes', 'ending', 'style_guide'] as $key) {
            if (isset($input[$key])) {
                $plan[$key] = self::clean($input[$key]);
            }
        }
        if (isset($input['world_rules'])) {
            $plan['world_rules'] = self::lines($input['world_rules']);
        }
        if (isset($input['characters']) && \is_array($input['characters'])) {
            $characters = [];
            foreach ($input['characters'] as $c) {
                if ('' === trim((string) ($c['name'] ?? ''))) {
                    continue;
                }
                $characters[] = [
                    'name' => self::clean($c['name']),
                    'role' => self::clean($c['role'] ?? ''),
                    'description' => self::clean($c['description'] ?? ''),
                    'goal' => self::clean($c['goal'] ?? ''),
                ];
            }
            $plan['characters'] = $characters;
        }
        if (isset($input['chapters']) && \is_array($input['chapters'])) {
            $chapters = [];
            for ($n = 1; $n <= $chapterCount; ++$n) {
                $c = $input['chapters'][$n] ?? [];
                $chapters[] = [
                    'number' => $n,
                    'title' => self::clean($c['title'] ?? 'Chapitre '.$n) ?: 'Chapitre '.$n,
                    'objective' => self::clean($c['objective'] ?? ''),
                    'events' => self::lines($c['events'] ?? ''),
                    'revelations' => self::lines($c['revelations'] ?? ''),
                    'end_state' => self::clean($c['end_state'] ?? ''),
                ];
            }
            $plan['chapters'] = $chapters;
        }

        return $plan;
    }

    private static function clean(mixed $value): string
    {
        return mb_substr(trim(str_replace("\r\n", "\n", (string) $value)), 0, 5000);
    }

    /** @return list<string> */
    private static function lines(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", self::clean($value))), static fn (string $l) => '' !== $l));
    }
}
