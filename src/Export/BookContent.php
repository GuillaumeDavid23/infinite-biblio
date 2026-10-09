<?php

namespace App\Export;

use App\Entity\Novel;
use App\Enum\ChapterStatus;

/**
 * Contenu exportable : version actuelle de chaque chapitre rédigé, dans l'ordre.
 */
final readonly class BookContent
{
    /**
     * @param list<array{number: int, title: string, body: string, accepted: bool}> $chapters
     */
    public function __construct(
        public string $title,
        public string $language,
        public array $chapters,
        public bool $simulated,
        public string $identifier,
    ) {
    }

    public static function fromNovel(Novel $novel): self
    {
        $chapters = [];
        $simulated = false;
        foreach ($novel->getChapters() as $chapter) {
            $version = $chapter->getCurrentVersion();
            if (null === $version) {
                continue;
            }
            $simulated = $simulated || $version->isSimulated();
            $chapters[] = [
                'number' => $chapter->getNumber(),
                'title' => $chapter->getTitle(),
                'body' => self::stripLeadingHeading($version->getContent()),
                'accepted' => ChapterStatus::Accepted === $chapter->getStatus(),
            ];
        }

        return new self($novel->getTitle(), 'fr', $chapters, $simulated, 'urn:uuid:'.self::uuidFor($novel));
    }

    /** Le modèle commence le chapitre par « # Titre » : déjà repris comme titre. */
    private static function stripLeadingHeading(string $content): string
    {
        return trim((string) preg_replace('/^\s*#[^\n]*\n/', '', $content, 1));
    }

    private static function uuidFor(Novel $novel): string
    {
        $h = md5('infinite-biblio-novel-'.$novel->getId().'-'.$novel->getCreatedAt()->format('U'));

        return \sprintf('%s-%s-3%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }
}
