<?php

namespace App\Ai\Provider;

use App\Enum\Step;

/**
 * Fournisseur simulé : aucun appel réseau, aucune dépense.
 * Les textes produits sont marqués « [SIMULATION] » et la consommation est fictive
 * (calculée à partir de la longueur des textes) pour pouvoir tester le suivi des coûts.
 */
final class SimulatedProvider implements TextGenerationProvider
{
    public const MARKER = '[SIMULATION]';

    /** @var list<string> défaillances programmées (tests et démonstrations) */
    private array $queuedFailures = [];

    public function __construct(
        private readonly string $pricingModel,
        private readonly int $delayMs = 0,
    ) {
    }

    public function name(): string
    {
        return 'simulation';
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function model(): string
    {
        return $this->pricingModel;
    }

    /**
     * Programme une défaillance pour le prochain appel :
     * « network » (erreur réseau réessayable), « invalid_json », « truncated », « refusal ».
     */
    public function queueFailure(string $kind): void
    {
        $this->queuedFailures[] = $kind;
    }

    public function countInputTokens(GenerationRequest $request): int
    {
        return $this->fakeTokens($request->system.$request->userPrompt);
    }

    public function generate(GenerationRequest $request): GenerationResult
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $failure = array_shift($this->queuedFailures);
        if ('network' === $failure) {
            throw new ProviderException('Erreur réseau simulée.', true);
        }

        $text = match ($request->step) {
            Step::Plan => json_encode($this->plan($request->simulationContext), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            Step::ChapterWrite => $this->chapter($request->simulationContext),
            Step::ChapterReview => json_encode($this->review($request->simulationContext), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            Step::MemoryUpdate => json_encode($this->memory($request->simulationContext), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            Step::Correction => json_encode($this->correction($request->simulationContext), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        };
        $stopReason = 'end_turn';

        if ('invalid_json' === $failure) {
            $text = '{"ceci n\'est pas" : du JSON valide';
        } elseif ('truncated' === $failure) {
            $text = mb_substr($text, 0, 40);
            $stopReason = 'max_tokens';
        } elseif ('refusal' === $failure) {
            $text = '';
            $stopReason = 'refusal';
        }

        $input = $this->countInputTokens($request);
        $output = min($request->maxOutputTokens, $this->fakeTokens($text));

        return new GenerationResult(
            $text,
            new TokenUsage($input, $output, 0, 0, 0, 0, ['simulated' => true, 'input_tokens' => $input, 'output_tokens' => $output]),
            'simulateur-local',
            $stopReason,
            'sim_'.bin2hex(random_bytes(6)),
        );
    }

    private function fakeTokens(string $text): int
    {
        // Valeur fictive, propre au simulateur. Les appels réels utilisent la consommation renvoyée par l'API.
        return max(1, intdiv(\strlen($text), 4));
    }

    /** @param array<string, mixed> $ctx */
    private function plan(array $ctx): array
    {
        $brief = $ctx['brief'] ?? [];
        $count = max(1, (int) ($brief['chapterCount'] ?? 3));
        $premise = (string) ($brief['premise'] ?? 'une histoire');
        $chapters = [];
        for ($i = 1; $i <= $count; ++$i) {
            $chapters[] = [
                'number' => $i,
                'title' => self::MARKER.' Chapitre '.$i,
                'objective' => 'Faire avancer l\'intrigue (étape '.$i.' sur '.$count.').',
                'events' => ['Événement simulé A du chapitre '.$i, 'Événement simulé B du chapitre '.$i],
                'revelations' => 1 === $i ? [] : ['Révélation simulée du chapitre '.$i],
                'end_state' => 'Situation simulée à la fin du chapitre '.$i.'.',
            ];
        }

        return [
            'title' => self::MARKER.' '.mb_substr($premise, 0, 60),
            'reformulation' => self::MARKER.' Reformulation de la demande : '.$premise,
            'synopsis' => self::MARKER.' Synopsis fictif généré sans IA pour tester le parcours.',
            'stakes' => 'Enjeux simulés.',
            'ending' => 'Fin simulée.',
            'characters' => [
                ['name' => 'Personnage simulé A', 'role' => 'protagoniste', 'description' => 'Fiche simulée.', 'goal' => 'Objectif simulé.'],
                ['name' => 'Personnage simulé B', 'role' => 'allié', 'description' => 'Fiche simulée.', 'goal' => 'Objectif simulé.'],
            ],
            'world_rules' => ['Règle d\'univers simulée.'],
            'style_guide' => 'Consignes de style simulées.',
            'chapters' => $chapters,
        ];
    }

    /** @param array<string, mixed> $ctx */
    private function chapter(array $ctx): string
    {
        $number = (int) ($ctx['chapterNumber'] ?? 1);
        $target = (int) ($ctx['targetWords'] ?? 1000);
        $sentence = 'Texte de remplacement produit par le simulateur, sans valeur littéraire, pour vérifier le parcours de génération. ';
        $paragraphs = [];
        $words = 0;
        $p = 0;
        while ($words < $target) {
            ++$p;
            $para = 'Paragraphe '.$p.' du chapitre '.$number.'. '.str_repeat($sentence, 4);
            $paragraphs[] = trim($para);
            $words += \count(preg_split('/\s+/', trim($para)) ?: []);
        }

        return "# ".self::MARKER." Chapitre {$number}\n\n".implode("\n\n", $paragraphs);
    }

    /** @param array<string, mixed> $ctx */
    private function review(array $ctx): array
    {
        return [
            'summary' => self::MARKER.' Contrôle simulé : aucune analyse réelle.',
            'issues' => [[
                'type' => 'other',
                'severity' => 'info',
                'description' => self::MARKER.' Exemple d\'alerte produite par le simulateur.',
                'excerpt' => '',
                'suggestion' => 'Aucune action nécessaire.',
            ]],
        ];
    }

    /** @param array<string, mixed> $ctx */
    private function memory(array $ctx): array
    {
        $previous = $ctx['previousMemory'] ?? [];
        $number = (int) ($ctx['chapterNumber'] ?? 1);
        $memory = array_merge([
            'timeline' => [], 'locations' => [], 'character_knowledge' => [],
            'relationships' => [], 'objects' => [], 'open_threads' => [], 'resolved_threads' => [],
        ], \is_array($previous) ? $previous : []);
        $memory['timeline'][] = ['chapter' => $number, 'event' => self::MARKER.' Événement retenu du chapitre '.$number];
        $memory['locations'][] = ['name' => 'Lieu simulé '.$number, 'note' => 'Visité au chapitre '.$number];
        $memory['objects'][] = ['name' => 'Objet simulé '.$number, 'holder' => 'Personnage simulé A', 'status' => 'en possession'];

        return $memory;
    }

    /** @param array<string, mixed> $ctx */
    private function correction(array $ctx): array
    {
        return [
            'revised_passage' => self::MARKER.' Passage corrigé : '.(string) ($ctx['passage'] ?? ''),
            'explanation' => 'Correction simulée.',
        ];
    }
}
