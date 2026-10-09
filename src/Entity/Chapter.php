<?php

namespace App\Entity;

use App\Enum\ChapterStatus;
use App\Exception\InvalidTransitionException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\ChapterRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_chapter_number', columns: ['novel_id', 'number'])]
class Chapter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'chapters')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Novel $novel;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 20, enumType: ChapterStatus::class)]
    private ChapterStatus $status = ChapterStatus::Planned;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ChapterVersion $currentVersion = null;

    /** Numéro de la version ayant reçu un contrôle de cohérence. */
    #[ORM\Column(nullable: true)]
    private ?int $reviewedVersionNumber = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    /** @var Collection<int, ChapterVersion> */
    #[ORM\OneToMany(targetEntity: ChapterVersion::class, mappedBy: 'chapter', cascade: ['persist'])]
    #[ORM\OrderBy(['number' => \SortDirection::Ascending])]
    private Collection $versions;

    public function __construct(Novel $novel, int $number, string $title)
    {
        $this->novel = $novel;
        $this->number = $number;
        $this->title = $title;
        $this->versions = new ArrayCollection();
        $novel->addChapter($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNovel(): Novel
    {
        return $this->novel;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getStatus(): ChapterStatus
    {
        return $this->status;
    }

    public function transitionTo(ChapterStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw InvalidTransitionException::for('le chapitre '.$this->number, $this->status->label(), $target->label());
        }
        $this->status = $target;
        if (ChapterStatus::Accepted === $target) {
            $this->acceptedAt = new \DateTimeImmutable();
        }
    }

    public function getCurrentVersion(): ?ChapterVersion
    {
        return $this->currentVersion;
    }

    public function setCurrentVersion(ChapterVersion $version): void
    {
        $this->currentVersion = $version;
    }

    public function getReviewedVersionNumber(): ?int
    {
        return $this->reviewedVersionNumber;
    }

    public function setReviewedVersionNumber(?int $number): void
    {
        $this->reviewedVersionNumber = $number;
    }

    public function isCurrentVersionReviewed(): bool
    {
        return null !== $this->currentVersion && $this->reviewedVersionNumber === $this->currentVersion->getNumber();
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    /** @return Collection<int, ChapterVersion> */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function addVersion(ChapterVersion $version): void
    {
        $this->versions->add($version);
    }

    public function getVersion(int $number): ?ChapterVersion
    {
        foreach ($this->versions as $version) {
            if ($version->getNumber() === $number) {
                return $version;
            }
        }

        return null;
    }

    public function nextVersionNumber(): int
    {
        $max = 0;
        foreach ($this->versions as $version) {
            $max = max($max, $version->getNumber());
        }

        return $max + 1;
    }

    /** @return array<string, mixed> */
    public function getPlanEntry(): array
    {
        foreach ($this->novel->getPlan()['chapters'] ?? [] as $entry) {
            if ((int) ($entry['number'] ?? 0) === $this->number) {
                return $entry;
            }
        }

        return [];
    }
}
