<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Appréciation humaine simple d'un roman (notes de 1 à 5).
 */
#[ORM\Entity]
class HumanReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Novel $novel;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $style;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $dialogues;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $coherence;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $correctionsNeeded;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Novel $novel, int $style, int $dialogues, int $coherence, ?string $correctionsNeeded, ?string $comment)
    {
        foreach ([$style, $dialogues, $coherence] as $score) {
            if ($score < 1 || $score > 5) {
                throw new \InvalidArgumentException('Les notes vont de 1 à 5.');
            }
        }
        $this->novel = $novel;
        $this->style = $style;
        $this->dialogues = $dialogues;
        $this->coherence = $coherence;
        $this->correctionsNeeded = $correctionsNeeded;
        $this->comment = $comment;
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

    public function getStyle(): int
    {
        return $this->style;
    }

    public function getDialogues(): int
    {
        return $this->dialogues;
    }

    public function getCoherence(): int
    {
        return $this->coherence;
    }

    public function getCorrectionsNeeded(): ?string
    {
        return $this->correctionsNeeded;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
