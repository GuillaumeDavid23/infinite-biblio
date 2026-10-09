<?php

namespace App\Generation;

use App\Ai\Pricing\CostCalculator;
use App\Ai\Pricing\PricingCatalog;
use App\Ai\Provider\GenerationRequest;
use App\Ai\Provider\GenerationResult;
use App\Ai\Provider\ProviderException;
use App\Ai\Provider\TextGenerationProvider;
use App\Entity\AiCall;
use App\Entity\Novel;
use App\Enum\AiCallStatus;
use App\Enum\Step;
use App\Repository\AiCallRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Point de passage unique vers l'IA : vérification du budget, journalisation, calcul du coût.
 */
final class AiGateway
{
    public function __construct(
        private readonly TextGenerationProvider $provider,
        private readonly PricingCatalog $pricing,
        private readonly CostCalculator $costs,
        private readonly AiCallRepository $calls,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function provider(): TextGenerationProvider
    {
        return $this->provider;
    }

    /**
     * @return array{0: AiCall, 1: GenerationResult}
     *
     * @throws BudgetExceededException
     * @throws StepFailedException
     */
    public function call(Novel $novel, Step $step, ?int $chapterNumber, ?int $jobId, int $attempt, string $promptVersion, GenerationRequest $request): array
    {
        $price = $this->pricing->find($this->provider->model());
        if (null === $price) {
            throw new StepFailedException(\sprintf('Aucun tarif connu pour le modèle « %s » : ajoutez-le dans config/ai/pricing.yaml. Appel non envoyé.', $this->provider->model()), false);
        }

        try {
            $inputTokens = $this->provider->countInputTokens($request);
        } catch (ProviderException $e) {
            throw new StepFailedException('Estimation impossible avant l\'appel : '.$e->getMessage(), $e->retryable, $e);
        }
        $ceiling = $this->costs->ceiling($inputTokens, $request->maxOutputTokens, $price);

        $simulated = $this->provider->isSimulated();
        $spent = $this->calls->sumCost($novel, $simulated) + $this->calls->sumPendingCeilings($novel, $simulated);
        if ($spent + $ceiling > $novel->getBudgetPico()) {
            throw new BudgetExceededException($spent, $ceiling, $novel->getBudgetPico());
        }

        $call = new AiCall($novel, $step, $chapterNumber, $jobId, $this->provider->name(), $simulated, $this->provider->model(), $promptVersion, $attempt, $request->maxOutputTokens);
        $call->recordEstimate($inputTokens, $ceiling);
        $this->em->persist($call);
        // Enregistré avant l'envoi : si le processus s'arrête pendant l'appel, la trace subsiste.
        $this->em->flush();

        $start = hrtime(true);
        try {
            $result = $this->provider->generate($request);
        } catch (ProviderException $e) {
            $call->finish(AiCallStatus::Error, $this->elapsedMs($start), null, $e->getMessage());
            $this->em->flush();
            $this->logger->warning('Appel IA en échec', ['call' => $call->getId(), 'step' => $step->value, 'retryable' => $e->retryable]);
            throw new StepFailedException($e->getMessage(), $e->retryable, $e);
        }

        $cost = null !== $result->usage ? $this->costs->actualCost($result->usage, $price) : null;
        if (null !== $result->usage) {
            $call->recordUsage($result->usage, $cost, $price->reference());
        }

        $error = null;
        if ($result->isTruncated()) {
            $error = 'Réponse coupée par la limite de sortie ('.$request->maxOutputTokens.' tokens).';
        } elseif ($result->isRefusal()) {
            $error = 'Le modèle a refusé de répondre à cette demande.';
        } elseif (null === $result->usage || !$result->usage->isComplete()) {
            $error = null; // Réponse exploitable, mais coût inconnu : signalé dans l'écran des coûts.
        }
        $call->finish(null === $error ? AiCallStatus::Success : AiCallStatus::Error, $this->elapsedMs($start), $result->stopReason, $error, $result->model, $result->requestId);
        $this->em->flush();

        if (null !== $error) {
            throw new StepFailedException($error, $result->isTruncated());
        }

        return [$call, $result];
    }

    /** Marque un appel réussi techniquement mais dont la réponse est inexploitable. */
    public function markInvalid(AiCall $call, string $message): void
    {
        $call->finish(AiCallStatus::Error, $call->getDurationMs() ?? 0, $call->getStopReason(), $message);
        $this->em->flush();
    }

    private function elapsedMs(int|float $start): int
    {
        return (int) ((hrtime(true) - $start) / 1_000_000);
    }
}
