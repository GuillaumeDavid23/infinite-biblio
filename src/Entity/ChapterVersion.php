<?php

namespace App\Entity;

use App\Enum\VersionSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Version immuable d'un chapitre. Une modification crée toujours une nouvelle version.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_chapter_version', columns: ['chapter_id', 'number'])]
class ChapterVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Chapter $chapter;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\Column]
    private int $wordCount;

    #[ORM\Column(length: 20, enumType: VersionSource::class)]
    private VersionSource $source;

    /** Motif de la correction ou de la modification. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(nullable: true)]
    private ?int $basedOnVersion = null;

    #[ORM\Column]
    private bool $simulated;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AiCall $aiCall = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Chapter $chapter, string $content, VersionSource $source, bool $simulated, ?string $reason = null, ?AiCall $aiCall = null)
    {
        $this->chapter = $chapter;
        $this->number = $chapter->nextVersionNumber();
        $this->basedOnVersion = $chapter->getCurrentVersion()?->getNumber();
        $this->content = $content;
        $this->wordCount = self::countWords($content);
        $this->source = $source;
        $this->simulated = $simulated;
        $this->reason = $reason;
        $this->aiCall = $aiCall;
        $this->createdAt = new \DateTimeImmutable();
        $chapter->addVersion($this);
    }

    public static function countWords(string $text): int
    {
        return \count(preg_split('/[\s\x{00A0}\x{2019}\']+/u', trim(strip_tags($text)), -1, \PREG_SPLIT_NO_EMPTY) ?: []);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChapter(): Chapter
    {
        return $this->chapter;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getWordCount(): int
    {
        return $this->wordCount;
    }

    public function getSource(): VersionSource
    {
        return $this->source;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getBasedOnVersion(): ?int
    {
        return $this->basedOnVersion;
    }

    public function isSimulated(): bool
    {
        return $this->simulated;
    }

    public function getAiCall(): ?AiCall
    {
        return $this->aiCall;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
