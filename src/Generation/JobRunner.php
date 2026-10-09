<?php

namespace App\Generation;

use App\Enum\JobStatus;
use App\Enum\NovelStatus;
use App\Enum\PauseReason;
use App\Repository\GenerationJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Exécute une tâche en arrière-plan : réservation atomique, reprises limitées, pause sur budget ou erreur.
 */
final class JobRunner
{
    public function __construct(
        private readonly GenerationJobRepository $jobs,
        private readonly GenerationSteps $steps,
        private readonly NovelOrchestrator $orchestrator,
        private readonly JobScheduler $scheduler,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly int $retryDelayMs,
    ) {
    }

    public function run(int $jobId): void
    {
        if (!$this->jobs->claim($jobId)) {
            // Déjà traitée ou en cours ailleurs : message en double, on l'ignore.
            return;
        }
        $job = $this->jobs->find($jobId);
        if (null === $job) {
            return;
        }
        $this->em->refresh($job);
        $novel = $job->getNovel();
        $this->em->refresh($novel);

        try {
            $this->steps->run($job);
            $job->finish(JobStatus::Done);
            $this->em->flush();
            $this->orchestrator->afterJob($novel);
        } catch (BudgetExceededException $e) {
            $this->steps->rollback($job);
            $job->finish(JobStatus::Cancelled, $e->getMessage());
            $this->pause($novel, PauseReason::Budget, $e->getMessage());
        } catch (StepFailedException $e) {
            $this->steps->rollback($job);
            if ($e->retryable && $job->canRetry()) {
                $job->requeue($e->getMessage());
                $this->em->flush();
                $this->scheduler->redispatch($job, $this->retryDelayMs * $job->getAttempts());

                return;
            }
            $job->finish(JobStatus::Failed, $e->getMessage());
            $this->pause($novel, PauseReason::Error, $job->getStep()->label().' : '.$e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Erreur inattendue pendant une étape', ['job' => $jobId, 'exception' => $e]);
            if (!$this->em->isOpen()) {
                throw $e;
            }
            $this->steps->rollback($job);
            $job->finish(JobStatus::Failed, 'Erreur interne : '.$e->getMessage());
            $this->pause($novel, PauseReason::Error, 'Erreur interne pendant « '.$job->getStep()->label().' ». Détails dans les journaux.');
        }
    }

    private function pause(\App\Entity\Novel $novel, PauseReason $reason, string $message): void
    {
        if (NovelStatus::Paused !== $novel->getStatus()) {
            $novel->pause($reason, $message);
        }
        $this->em->flush();
    }
}
