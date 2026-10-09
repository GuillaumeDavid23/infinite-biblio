<?php

namespace App\Generation;

use App\Entity\Chapter;
use App\Entity\Novel;
use App\Enum\ChapterStatus;
use App\Enum\JobStatus;
use App\Enum\NovelStatus;
use App\Enum\PauseReason;
use App\Enum\Step;
use App\Exception\UserFacingException;
use App\Repository\AiCallRepository;
use App\Repository\ConsistencyIssueRepository;
use App\Repository\GenerationJobRepository;
use App\Repository\MemorySnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Décide de la prochaine étape à partir de l'état enregistré.
 * C'est ce qui permet de reprendre après une interruption sans refaire ce qui est déjà livré.
 */
final class NovelOrchestrator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JobScheduler $scheduler,
        private readonly GenerationJobRepository $jobs,
        private readonly MemorySnapshotRepository $memories,
        private readonly ConsistencyIssueRepository $issues,
        private readonly AiCallRepository $calls,
        private readonly ChapterService $chapters,
        private readonly GenerationSteps $steps,
        private readonly int $staleAfterSeconds,
    ) {
    }

    public function requestPlan(Novel $novel): void
    {
        foreach ($novel->getChapters() as $chapter) {
            if (null !== $chapter->getCurrentVersion()) {
                throw new UserFacingException('Des chapitres sont déjà rédigés : le plan ne peut plus être régénéré.');
            }
        }
        if (!\in_array($novel->getStatus(), [NovelStatus::Draft, NovelStatus::PlanReady, NovelStatus::Paused], true)) {
            throw new UserFacingException('Le plan ne peut pas être généré dans l\'état actuel ('.$novel->getStatus()->label().').');
        }
        $this->guardNoActiveJob($novel);
        $novel->transitionTo(NovelStatus::Planning);
        $this->em->flush();
        try {
            $this->scheduler->schedule($novel, Step::Plan);
        } catch (UserFacingException $e) {
            $novel->transitionTo(NovelStatus::Paused);
            $this->em->flush();
            throw $e;
        }
    }

    public function validatePlan(Novel $novel): void
    {
        if (NovelStatus::PlanReady !== $novel->getStatus() || null === $novel->getPlan()) {
            throw new UserFacingException('Aucun plan à valider.');
        }
        $this->steps->syncChaptersFromPlan($novel);
        $novel->setPlanValidatedAt(new \DateTimeImmutable());
        $novel->transitionTo(NovelStatus::Writing);
        $this->em->flush();
    }

    /**
     * Programme la prochaine étape nécessaire.
     *
     * @param bool $explicit true si l'humain a demandé la rédaction du chapitre suivant
     *
     * @return string message lisible décrivant ce qui a été fait
     */
    public function advance(Novel $novel, bool $explicit = false): string
    {
        if (NovelStatus::Writing !== $novel->getStatus()) {
            return 'Rien à lancer : le roman est « '.$novel->getStatus()->label().' ».';
        }
        if (null !== $this->jobs->findActive($novel)) {
            return 'Une étape est déjà en cours.';
        }

        foreach ($novel->getChapters() as $chapter) {
            switch ($chapter->getStatus()) {
                case ChapterStatus::Accepted:
                    if (null === $this->memories->findValid($novel, $chapter->getNumber())) {
                        $this->scheduler->schedule($novel, Step::MemoryUpdate, $chapter->getNumber());

                        return 'Mise à jour de la mémoire après le chapitre '.$chapter->getNumber().' lancée.';
                    }
                    continue 2;
                case ChapterStatus::Writing:
                    return 'Le chapitre '.$chapter->getNumber().' est marqué en rédaction : utilisez « Reprendre » si le traitement s\'est arrêté.';
                case ChapterStatus::Planned:
                    if (!$explicit) {
                        return 'Prêt à rédiger le chapitre '.$chapter->getNumber().'.';
                    }
                    $this->scheduler->schedule($novel, Step::ChapterWrite, $chapter->getNumber());

                    return 'Rédaction du chapitre '.$chapter->getNumber().' lancée.';
                case ChapterStatus::ToReview:
                    if (!$chapter->isCurrentVersionReviewed()) {
                        $this->scheduler->schedule($novel, Step::ChapterReview, $chapter->getNumber());

                        return 'Contrôle de cohérence du chapitre '.$chapter->getNumber().' lancé.';
                    }
                    if ($novel->isAutoAccept() && 0 === $this->issues->countOpenCritical($chapter)) {
                        $this->chapters->accept($chapter);
                        $this->em->flush();

                        return $this->advance($novel, $explicit);
                    }

                    return 'Le chapitre '.$chapter->getNumber().' attend votre relecture et votre acceptation.';
                case ChapterStatus::ToRecheck:
                    return 'Le chapitre '.$chapter->getNumber().' est à revérifier après une modification antérieure.';
            }
        }

        $novel->transitionTo(NovelStatus::Completed);
        $this->em->flush();

        return 'Tous les chapitres sont acceptés : le roman est terminé.';
    }

    /** Appelé à la fin de chaque tâche réussie. */
    public function afterJob(Novel $novel): void
    {
        if ($novel->isPauseRequested()) {
            $novel->pause(PauseReason::User, 'Interrompu après l\'étape en cours, comme demandé.');
            $this->em->flush();

            return;
        }
        if (NovelStatus::Writing === $novel->getStatus()) {
            $this->advance($novel, $novel->isAutoContinue());
        }
    }

    public function requestPause(Novel $novel): string
    {
        if (null !== $this->jobs->findActive($novel)) {
            $novel->setPauseRequested(true);
            $this->em->flush();

            return 'L\'étape en cours va se terminer, puis la génération s\'arrêtera. Le coût de l\'appel en cours reste dû.';
        }
        if (\in_array($novel->getStatus(), [NovelStatus::Writing, NovelStatus::Planning, NovelStatus::PlanReady], true)) {
            $novel->pause(PauseReason::User, 'Mis en pause.');
            $this->em->flush();
        }

        return 'Génération en pause.';
    }

    public function resume(Novel $novel): string
    {
        $recovered = $this->recoverStale($novel);
        if (null !== $this->jobs->findActive($novel)) {
            return 'Une étape est encore en cours. Si le traitement en arrière-plan est arrêté, relancez-le (voir README), ou réessayez dans '.ceil($this->staleAfterSeconds / 60).' minutes.';
        }
        $novel->setPauseRequested(false);

        if (NovelStatus::Paused === $novel->getStatus() || NovelStatus::Planning === $novel->getStatus()) {
            if (null === $novel->getPlan()) {
                if (NovelStatus::Planning === $novel->getStatus()) {
                    $novel->transitionTo(NovelStatus::Paused);
                }
                $this->em->flush();
                $this->requestPlan($novel);

                return ($recovered ? 'Étape interrompue détectée. ' : '').'Génération du plan relancée.';
            }
            $novel->transitionTo($novel->isPlanValidated() ? NovelStatus::Writing : NovelStatus::PlanReady);
            $this->em->flush();
        }

        $message = $this->advance($novel, true);

        return ($recovered ? 'Étape interrompue détectée et nettoyée. ' : '').$message;
    }

    /**
     * Libère les tâches bloquées (processus arrêté pendant un appel).
     * Les chapitres déjà livrés sont conservés ; seule l'étape interrompue sera refaite.
     */
    public function recoverStale(Novel $novel, ?int $olderThanSeconds = null): bool
    {
        $before = new \DateTimeImmutable('-'.($olderThanSeconds ?? $this->staleAfterSeconds).' seconds');
        $recovered = false;
        foreach ($this->jobs->findStaleRunning($novel, $before) as $job) {
            $this->steps->rollback($job);
            $job->finish(JobStatus::Interrupted, 'Étape interrompue (processus arrêté ou bloqué).');
            $recovered = true;
        }
        foreach ($this->calls->findPending($novel) as $call) {
            if ($call->getStartedAt() < $before) {
                $call->markInterrupted('Appel interrompu : il a pu être facturé sans que la consommation soit connue.');
            }
        }
        if (NovelStatus::Planning === $novel->getStatus() && $recovered) {
            $novel->pause(PauseReason::Error, 'La génération du plan a été interrompue.');
        }
        $this->em->flush();

        return $recovered;
    }

    public function requestCorrection(Chapter $chapter, string $passage, string $reason): void
    {
        $novel = $chapter->getNovel();
        $version = $chapter->getCurrentVersion() ?? throw new UserFacingException('Ce chapitre n\'a pas de texte.');
        $passage = trim(str_replace("\r\n", "\n", $passage));
        $reason = trim($reason);
        if ('' === $reason) {
            throw new UserFacingException('Indiquez le motif de la correction.');
        }
        ChapterService::replacePassage($version->getContent(), $passage, ''); // vérifie que le passage est unique
        if (\in_array($novel->getStatus(), [NovelStatus::Completed, NovelStatus::Paused], true)) {
            $novel->transitionTo(NovelStatus::Writing);
        }
        if (NovelStatus::Writing !== $novel->getStatus()) {
            throw new UserFacingException('Correction impossible dans l\'état actuel du roman.');
        }
        $this->guardNoActiveJob($novel);
        $this->em->flush();
        $this->scheduler->schedule($novel, Step::Correction, $chapter->getNumber(), [
            'passage' => $passage,
            'reason' => mb_substr($reason, 0, 2000),
            'baseVersion' => $version->getNumber(),
        ]);
    }

    public function reopenIfCompleted(Novel $novel): void
    {
        if (\in_array($novel->getStatus(), [NovelStatus::Completed, NovelStatus::Paused], true) && $novel->isPlanValidated()) {
            $novel->transitionTo(NovelStatus::Writing);
        }
    }

    private function guardNoActiveJob(Novel $novel): void
    {
        if (null !== $this->jobs->findActive($novel)) {
            throw new UserFacingException('Une étape est déjà en cours pour ce roman.');
        }
    }
}
