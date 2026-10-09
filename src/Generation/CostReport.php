<?php

namespace App\Generation;

use App\Ai\Pricing\PricingCatalog;
use App\Ai\Provider\TextGenerationProvider;
use App\Entity\Novel;
use App\Enum\AiCallStatus;
use App\Enum\ChapterStatus;
use App\Enum\Step;
use App\Repository\AiCallRepository;

/**
 * Synthèse des coûts d'un roman : consommation constatée (réelle et simulée séparées)
 * et estimation maximale des étapes restantes.
 */
final class CostReport
{
    public function __construct(
        private readonly AiCallRepository $calls,
        private readonly PricingCatalog $pricing,
        private readonly TextGenerationProvider $provider,
        private readonly GenerationSteps $steps,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forNovel(Novel $novel): array
    {
        $calls = $this->calls->findForNovel($novel);
        $byStep = [];
        $real = 0;
        $simulated = 0;
        $errors = 0;
        $unknown = 0;
        foreach ($calls as $call) {
            $key = $call->getStep()->value;
            $byStep[$key] ??= ['label' => $call->getStep()->label(), 'count' => 0, 'real' => 0, 'simulated' => 0, 'input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0];
            ++$byStep[$key]['count'];
            $cost = $call->getCostPico() ?? 0;
            if ($call->isSimulated()) {
                $byStep[$key]['simulated'] += $cost;
                $simulated += $cost;
            } else {
                $byStep[$key]['real'] += $cost;
                $real += $cost;
            }
            $byStep[$key]['input'] += $call->getInputTokens() ?? 0;
            $byStep[$key]['output'] += $call->getOutputTokens() ?? 0;
            $byStep[$key]['cacheRead'] += $call->getCacheReadInputTokens() ?? 0;
            $byStep[$key]['cacheWrite'] += $call->getCacheCreationInputTokens() ?? 0;
            if (AiCallStatus::Success !== $call->getStatus()) {
                ++$errors;
            }
            if (null === $call->getCostPico() && AiCallStatus::Pending !== $call->getStatus()) {
                ++$unknown;
            }
        }

        $spentForBudget = $this->provider->isSimulated() ? $simulated : $real;

        return [
            'calls' => $calls,
            'byStep' => $byStep,
            'real' => $real,
            'simulated' => $simulated,
            'errors' => $errors,
            'unknown' => $unknown,
            'budget' => $novel->getBudgetPico(),
            'spentForBudget' => $spentForBudget,
            'remainingBudget' => $novel->getBudgetPico() - $spentForBudget,
            'remainingCeiling' => $this->remainingOutputCeiling($novel),
            'providerSimulated' => $this->provider->isSimulated(),
            'model' => $this->provider->model(),
            'price' => $this->pricing->find($this->provider->model()),
        ];
    }

    /**
     * Plafond de sortie des étapes restantes (sans l'entrée, inconnue avant de construire les consignes).
     * Une borne haute indicative, pas une prévision.
     */
    public function remainingOutputCeiling(Novel $novel): ?int
    {
        $price = $this->pricing->find($this->provider->model());
        if (null === $price) {
            return null;
        }
        $tokens = 0;
        if (null === $novel->getPlan()) {
            $tokens += $this->steps->maxOutputTokens(Step::Plan);
        }
        $chapters = $novel->getChapters()->count() > 0 ? $novel->getChapters()->count() : $novel->getChapterCount();
        $done = 0;
        foreach ($novel->getChapters() as $chapter) {
            if (ChapterStatus::Accepted === $chapter->getStatus()) {
                ++$done;
            } elseif (null !== $chapter->getCurrentVersion()) {
                $tokens += $this->steps->maxOutputTokens(Step::ChapterReview) + $this->steps->maxOutputTokens(Step::MemoryUpdate);
                ++$done;
            }
        }
        $tokens += ($chapters - $done) * ($this->steps->maxOutputTokens(Step::ChapterWrite) + $this->steps->maxOutputTokens(Step::ChapterReview) + $this->steps->maxOutputTokens(Step::MemoryUpdate));

        return $tokens * $price->outputMicro;
    }
}
