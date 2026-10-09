<?php

namespace App\Entity;

use App\Enum\JobStatus;
use App\Enum\Step;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tâche de génération en arrière-plan. Une seule tâche active par roman :
 * la colonne active_novel_id (unique) n'est renseignée que pendant l'attente ou l'exécution,
 * ce qui empêche les doubles commandes même en cas de clics simultanés.
 */
#[ORM\Entity(repositoryClass: \App\Repository\GenerationJobRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_job_active_novel', columns: ['active_novel_id'])]
class GenerationJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Novel $novel;

    #[ORM\Column(length: 30, enumType: Step::class)]
    private Step $step;

    #[ORM\Column(nullable: true)]
    private ?int $chapterNumber;

    #[ORM\Column(length: 20, enumType: JobStatus::class)]
    private JobStatus $status = JobStatus::Queued;

    #[ORM\Column(nullable: true)]
    private ?int $activeNovelId;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column]
    private int $maxAttempts;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $payload */
    public function __construct(Novel $novel, Step $step, ?int $chapterNumber, int $maxAttempts, array $payload = [])
    {
        $this->novel = $novel;
        $this->step = $step;
        $this->chapterNumber = $chapterNumber;
        $this->maxAttempts = max(1, $maxAttempts);
        $this->payload = $payload;
        $this->activeNovelId = $novel->getId();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNovel(): Novel
    {
        return $this->novel;
    }

    public function getStep(): Step
    {
        return $this->step;
    }

    public function getChapterNumber(): ?int
    {
        return $this->chapterNumber;
    }

    public function getStatus(): JobStatus
    {
        return $this->status;
    }

    public function requeue(string $error): void
    {
        $this->status = JobStatus::Queued;
        $this->lastError = $error;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function finish(JobStatus $status, ?string $error = null): void
    {
        if ($status->isActive()) {
            throw new \LogicException('Statut final attendu.');
        }
        $this->status = $status;
        $this->activeNovelId = null;
        if (null !== $error) {
            $this->lastError = $error;
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function canRetry(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
