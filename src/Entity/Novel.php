<?php

namespace App\Entity;

use App\Enum\ChapterStatus;
use App\Enum\NovelStatus;
use App\Enum\PauseReason;
use App\Exception\InvalidTransitionException;
use App\Repository\NovelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NovelRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Novel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $title;

    /**
     * Demande du lecteur (voir App\Model\NovelBrief). Données, jamais instructions.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $brief = [];

    #[ORM\Column(length: 20, enumType: NovelStatus::class)]
    private NovelStatus $status = NovelStatus::Draft;

    #[ORM\Column(length: 20, nullable: true, enumType: PauseReason::class)]
    private ?PauseReason $pauseReason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pauseMessage = null;

    /** Demande d'interruption après l'étape en cours. */
    #[ORM\Column]
    private bool $pauseRequested = false;

    /** Enchaîner automatiquement les étapes suivantes (rédaction, contrôle, mémoire). */
    #[ORM\Column]
    private bool $autoContinue = true;

    /** Accepter automatiquement un chapitre sans alerte bloquante. */
    #[ORM\Column]
    private bool $autoAccept = false;

    /**
     * Proposition d'histoire et plan (synopsis, personnages, règles, chapitres).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $plan = null;

    #[ORM\Column]
    private int $planRevision = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $planValidatedAt = null;

    /** Budget maximal en picodollars (1 $ = 10^12). */
    #[ORM\Column(type: Types::BIGINT)]
    private int|string $budgetPico = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Chapter> */
    #[ORM\OneToMany(targetEntity: Chapter::class, mappedBy: 'novel', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => \SortDirection::Ascending])]
    private Collection $chapters;

    public function __construct(string $title)
    {
        $this->title = $title;
        $this->chapters = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /** @return array<string, mixed> */
    public function getBrief(): array
    {
        return $this->brief;
    }

    /** @param array<string, mixed> $brief */
    public function setBrief(array $brief): void
    {
        $this->brief = $brief;
    }

    public function getStatus(): NovelStatus
    {
        return $this->status;
    }

    public function transitionTo(NovelStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw InvalidTransitionException::for('le roman', $this->status->label(), $target->label());
        }
        $this->status = $target;
        if (NovelStatus::Paused !== $target) {
            $this->pauseReason = null;
            $this->pauseMessage = null;
        }
    }

    public function pause(PauseReason $reason, ?string $message = null): void
    {
        $this->transitionTo(NovelStatus::Paused);
        $this->pauseReason = $reason;
        $this->pauseMessage = $message;
        $this->pauseRequested = false;
    }

    public function getPauseReason(): ?PauseReason
    {
        return $this->pauseReason;
    }

    public function getPauseMessage(): ?string
    {
        return $this->pauseMessage;
    }

    public function isPauseRequested(): bool
    {
        return $this->pauseRequested;
    }

    public function setPauseRequested(bool $pauseRequested): void
    {
        $this->pauseRequested = $pauseRequested;
    }

    public function isAutoContinue(): bool
    {
        return $this->autoContinue;
    }

    public function setAutoContinue(bool $autoContinue): void
    {
        $this->autoContinue = $autoContinue;
    }

    public function isAutoAccept(): bool
    {
        return $this->autoAccept;
    }

    public function setAutoAccept(bool $autoAccept): void
    {
        $this->autoAccept = $autoAccept;
    }

    /** @return array<string, mixed>|null */
    public function getPlan(): ?array
    {
        return $this->plan;
    }

    /** @param array<string, mixed> $plan */
    public function setPlan(array $plan): void
    {
        $this->plan = $plan;
        ++$this->planRevision;
    }

    public function getPlanRevision(): int
    {
        return $this->planRevision;
    }

    public function getPlanValidatedAt(): ?\DateTimeImmutable
    {
        return $this->planValidatedAt;
    }

    public function setPlanValidatedAt(?\DateTimeImmutable $planValidatedAt): void
    {
        $this->planValidatedAt = $planValidatedAt;
    }

    public function isPlanValidated(): bool
    {
        return null !== $this->planValidatedAt;
    }

    public function getBudgetPico(): int
    {
        return (int) $this->budgetPico;
    }

    public function setBudgetPico(int $budgetPico): void
    {
        $this->budgetPico = $budgetPico;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, Chapter> */
    public function getChapters(): Collection
    {
        return $this->chapters;
    }

    public function getChapter(int $number): ?Chapter
    {
        foreach ($this->chapters as $chapter) {
            if ($chapter->getNumber() === $number) {
                return $chapter;
            }
        }

        return null;
    }

    public function addChapter(Chapter $chapter): void
    {
        if (!$this->chapters->contains($chapter)) {
            $this->chapters->add($chapter);
        }
    }

    public function removeChapter(Chapter $chapter): void
    {
        $this->chapters->removeElement($chapter);
    }

    public function countAcceptedChapters(): int
    {
        return $this->chapters->filter(static fn (Chapter $c) => ChapterStatus::Accepted === $c->getStatus())->count();
    }

    public function getTotalWordCount(): int
    {
        $total = 0;
        foreach ($this->chapters as $chapter) {
            $total += $chapter->getCurrentVersion()?->getWordCount() ?? 0;
        }

        return $total;
    }

    public function getTargetWordsPerChapter(): int
    {
        return (int) ($this->brief['targetWordsPerChapter'] ?? 1000);
    }

    public function getChapterCount(): int
    {
        return (int) ($this->brief['chapterCount'] ?? 3);
    }
}
