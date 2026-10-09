<?php

namespace App\Entity;

use App\Enum\IssueSeverity;
use App\Enum\IssueStatus;
use App\Enum\IssueType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Alerte de cohérence. Aide à la relecture, pas une garantie de qualité.
 */
#[ORM\Entity(repositoryClass: \App\Repository\ConsistencyIssueRepository::class)]
class ConsistencyIssue
{
    public const ORIGIN_AI = 'ia';
    public const ORIGIN_LOCAL = 'local';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Chapter $chapter;

    #[ORM\Column]
    private int $versionNumber;

    #[ORM\Column(length: 30, enumType: IssueType::class)]
    private IssueType $type;

    #[ORM\Column(length: 20, enumType: IssueSeverity::class)]
    private IssueSeverity $severity;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $excerpt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $suggestion;

    #[ORM\Column(length: 20, enumType: IssueStatus::class)]
    private IssueStatus $status = IssueStatus::Open;

    #[ORM\Column(length: 10)]
    private string $origin;

    #[ORM\Column]
    private bool $simulated;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Chapter $chapter, int $versionNumber, IssueType $type, IssueSeverity $severity, string $description, ?string $excerpt, ?string $suggestion, string $origin, bool $simulated)
    {
        $this->chapter = $chapter;
        $this->versionNumber = $versionNumber;
        $this->type = $type;
        $this->severity = $severity;
        $this->description = $description;
        $this->excerpt = $excerpt;
        $this->suggestion = $suggestion;
        $this->origin = $origin;
        $this->simulated = $simulated;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChapter(): Chapter
    {
        return $this->chapter;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getType(): IssueType
    {
        return $this->type;
    }

    public function getSeverity(): IssueSeverity
    {
        return $this->severity;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }

    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    public function getStatus(): IssueStatus
    {
        return $this->status;
    }

    public function setStatus(IssueStatus $status): void
    {
        $this->status = $status;
    }

    public function getOrigin(): string
    {
        return $this->origin;
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
