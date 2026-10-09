<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * État de la mémoire narrative après un chapitre accepté.
 */
#[ORM\Entity(repositoryClass: \App\Repository\MemorySnapshotRepository::class)]
class MemorySnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Novel $novel;

    #[ORM\Column]
    private int $afterChapter;

    #[ORM\Column]
    private int $chapterVersionNumber;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $data;

    /** Périmée : un chapitre antérieur ou celui-ci a changé depuis. */
    #[ORM\Column]
    private bool $stale = false;

    #[ORM\Column]
    private bool $simulated;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $data */
    public function __construct(Novel $novel, int $afterChapter, int $chapterVersionNumber, array $data, bool $simulated)
    {
        $this->novel = $novel;
        $this->afterChapter = $afterChapter;
        $this->chapterVersionNumber = $chapterVersionNumber;
        $this->data = $data;
        $this->simulated = $simulated;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNovel(): Novel
    {
        return $this->novel;
    }

    public function getAfterChapter(): int
    {
        return $this->afterChapter;
    }

    public function getChapterVersionNumber(): int
    {
        return $this->chapterVersionNumber;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    public function isStale(): bool
    {
        return $this->stale;
    }

    public function markStale(): void
    {
        $this->stale = true;
    }

    public function isSimulated(): bool
    {
        return $this->simulated;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
