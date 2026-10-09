<?php

namespace App\Generation;

use App\Entity\AiCall;
use App\Entity\Chapter;
use App\Entity\ChapterVersion;
use App\Enum\ChapterStatus;
use App\Enum\VersionSource;
use App\Exception\UserFacingException;
use App\Repository\MemorySnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Règles de versionnage : une modification crée toujours une nouvelle version ;
 * un chapitre accepté qui change rend les chapitres suivants « à revérifier ».
 */
final class ChapterService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MemorySnapshotRepository $memories,
    ) {
    }

    public function addVersion(Chapter $chapter, string $content, VersionSource $source, bool $simulated, ?string $reason = null, ?AiCall $aiCall = null): ChapterVersion
    {
        $content = trim($content);
        if ('' === $content) {
            throw new UserFacingException('Le texte du chapitre est vide.');
        }

        $previous = $chapter->getCurrentVersion();
        $wasAccepted = \in_array($chapter->getStatus(), [ChapterStatus::Accepted, ChapterStatus::ToRecheck], true);

        $version = new ChapterVersion($chapter, $content, $source, $simulated, $reason, $aiCall);
        $this->em->persist($version);
        $chapter->setCurrentVersion($version);
        $chapter->setReviewedVersionNumber(null);
        $chapter->transitionTo(ChapterStatus::ToReview);

        if (null !== $previous && $wasAccepted) {
            $this->invalidateFrom($chapter);
        }

        return $version;
    }

    public function accept(Chapter $chapter): void
    {
        if (null === $chapter->getCurrentVersion()) {
            throw new UserFacingException('Ce chapitre n\'a pas encore de texte.');
        }
        if (!\in_array($chapter->getStatus(), [ChapterStatus::ToReview, ChapterStatus::ToRecheck], true)) {
            throw new UserFacingException('Ce chapitre ne peut pas être accepté dans son état actuel ('.$chapter->getStatus()->label().').');
        }
        $previous = $chapter->getNovel()->getChapter($chapter->getNumber() - 1);
        if (null !== $previous && ChapterStatus::Accepted !== $previous->getStatus()) {
            throw new UserFacingException(\sprintf('Acceptez d\'abord le chapitre %d : la mémoire narrative se construit dans l\'ordre.', $previous->getNumber()));
        }
        $chapter->transitionTo(ChapterStatus::Accepted);
    }

    public function restore(Chapter $chapter, int $versionNumber): ChapterVersion
    {
        $old = $chapter->getVersion($versionNumber);
        if (null === $old) {
            throw new UserFacingException('Version introuvable.');
        }
        if ($chapter->getCurrentVersion()?->getNumber() === $versionNumber) {
            throw new UserFacingException('Cette version est déjà la version actuelle.');
        }

        return $this->addVersion($chapter, $old->getContent(), VersionSource::Restore, $old->isSimulated(), 'Restauration de la version '.$versionNumber);
    }

    /**
     * Remplace un passage unique dans le texte. Refuse si le passage est absent ou ambigu.
     */
    public static function replacePassage(string $text, string $passage, string $replacement): string
    {
        $passage = trim($passage);
        if ('' === $passage) {
            throw new UserFacingException('Le passage à corriger est vide.');
        }
        $count = substr_count($text, $passage);
        if (0 === $count) {
            throw new UserFacingException('Passage introuvable dans la version actuelle : copiez-le exactement depuis le texte.');
        }
        if ($count > 1) {
            throw new UserFacingException('Ce passage apparaît plusieurs fois : sélectionnez un extrait plus long pour lever l\'ambiguïté.');
        }
        $pos = strpos($text, $passage);

        return substr($text, 0, (int) $pos).trim($replacement).substr($text, (int) $pos + \strlen($passage));
    }

    private function invalidateFrom(Chapter $changed): void
    {
        $this->memories->markStaleFrom($changed->getNovel(), $changed->getNumber());
        foreach ($changed->getNovel()->getChapters() as $chapter) {
            if ($chapter->getNumber() > $changed->getNumber() && ChapterStatus::Accepted === $chapter->getStatus()) {
                $chapter->transitionTo(ChapterStatus::ToRecheck);
            }
        }
    }
}
