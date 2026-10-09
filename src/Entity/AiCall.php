<?php

namespace App\Entity;

use App\Ai\Provider\TokenUsage;
use App\Enum\AiCallStatus;
use App\Enum\Step;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d'un appel à l'IA : étape, modèle, consommation constatée, coût, durée, erreurs.
 * Les montants sont en picodollars (1 $ = 10^12) pour éviter les arrondis.
 */
#[ORM\Entity(repositoryClass: \App\Repository\AiCallRepository::class)]
#[ORM\Index(name: 'idx_ai_call_novel', columns: ['novel_id'])]
class AiCall
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Novel $novel;

    #[ORM\Column(nullable: true)]
    private ?int $chapterNumber;

    #[ORM\Column(length: 30, enumType: Step::class)]
    private Step $step;

    #[ORM\Column(nullable: true)]
    private ?int $jobId;

    #[ORM\Column(length: 30)]
    private string $provider;

    #[ORM\Column]
    private bool $simulated;

    /** Modèle demandé. */
    #[ORM\Column(length: 100)]
    private string $model;

    /** Modèle exact renvoyé par l'API. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $responseModel = null;

    #[ORM\Column(length: 100)]
    private string $promptVersion;

    #[ORM\Column]
    private int $attempt;

    #[ORM\Column]
    private int $maxOutputTokens;

    #[ORM\Column(length: 20, enumType: AiCallStatus::class)]
    private AiCallStatus $status = AiCallStatus::Pending;

    #[ORM\Column(nullable: true)]
    private ?int $inputTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $outputTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $cacheCreationInputTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $cacheCreation5mTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $cacheCreation1hTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $cacheReadInputTokens = null;

    /** @var array<string, mixed>|null Consommation brute telle que renvoyée. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $usageRaw = null;

    /** Coût constaté, calculé à partir de la consommation renvoyée. Null si inconnue. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private int|string|null $costPico = null;

    /** Référence de la grille tarifaire utilisée (modèle@date). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $pricingRef = null;

    /** Estimation maximale avant l'appel (entrée comptée + sortie maximale). */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private int|string|null $ceilingPico = null;

    #[ORM\Column(nullable: true)]
    private ?int $estimatedInputTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $stopReason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $requestId = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(Novel $novel, Step $step, ?int $chapterNumber, ?int $jobId, string $provider, bool $simulated, string $model, string $promptVersion, int $attempt, int $maxOutputTokens)
    {
        $this->novel = $novel;
        $this->step = $step;
        $this->chapterNumber = $chapterNumber;
        $this->jobId = $jobId;
        $this->provider = $provider;
        $this->simulated = $simulated;
        $this->model = $model;
        $this->promptVersion = $promptVersion;
        $this->attempt = $attempt;
        $this->maxOutputTokens = $maxOutputTokens;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function recordEstimate(?int $estimatedInputTokens, ?int $ceilingPico): void
    {
        $this->estimatedInputTokens = $estimatedInputTokens;
        $this->ceilingPico = $ceilingPico;
    }

    public function recordUsage(TokenUsage $usage, ?int $costPico, ?string $pricingRef): void
    {
        $this->inputTokens = $usage->inputTokens;
        $this->outputTokens = $usage->outputTokens;
        $this->cacheCreationInputTokens = $usage->cacheCreationInputTokens;
        $this->cacheCreation5mTokens = $usage->cacheCreation5mTokens;
        $this->cacheCreation1hTokens = $usage->cacheCreation1hTokens;
        $this->cacheReadInputTokens = $usage->cacheReadInputTokens;
        $this->usageRaw = $usage->raw;
        $this->costPico = $costPico;
        $this->pricingRef = $pricingRef;
    }

    public function finish(AiCallStatus $status, int $durationMs, ?string $stopReason = null, ?string $errorMessage = null, ?string $responseModel = null, ?string $requestId = null): void
    {
        $this->status = $status;
        $this->durationMs = $durationMs;
        $this->stopReason = $stopReason;
        $this->errorMessage = $errorMessage;
        $this->responseModel = $responseModel ?? $this->responseModel;
        $this->requestId = $requestId ?? $this->requestId;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function markInterrupted(string $message): void
    {
        $this->status = AiCallStatus::Interrupted;
        $this->errorMessage = $message;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNovel(): Novel
    {
        return $this->novel;
    }

    public function getChapterNumber(): ?int
    {
        return $this->chapterNumber;
    }

    public function getStep(): Step
    {
        return $this->step;
    }

    public function getJobId(): ?int
    {
        return $this->jobId;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function isSimulated(): bool
    {
        return $this->simulated;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getResponseModel(): ?string
    {
        return $this->responseModel;
    }

    public function getPromptVersion(): string
    {
        return $this->promptVersion;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function getMaxOutputTokens(): int
    {
        return $this->maxOutputTokens;
    }

    public function getStatus(): AiCallStatus
    {
        return $this->status;
    }

    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function getCacheCreationInputTokens(): ?int
    {
        return $this->cacheCreationInputTokens;
    }

    public function getCacheCreation5mTokens(): ?int
    {
        return $this->cacheCreation5mTokens;
    }

    public function getCacheCreation1hTokens(): ?int
    {
        return $this->cacheCreation1hTokens;
    }

    public function getCacheReadInputTokens(): ?int
    {
        return $this->cacheReadInputTokens;
    }

    /** @return array<string, mixed>|null */
    public function getUsageRaw(): ?array
    {
        return $this->usageRaw;
    }

    public function getCostPico(): ?int
    {
        return null === $this->costPico ? null : (int) $this->costPico;
    }

    public function getPricingRef(): ?string
    {
        return $this->pricingRef;
    }

    public function getCeilingPico(): ?int
    {
        return null === $this->ceilingPico ? null : (int) $this->ceilingPico;
    }

    public function getEstimatedInputTokens(): ?int
    {
        return $this->estimatedInputTokens;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getStopReason(): ?string
    {
        return $this->stopReason;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }
}
