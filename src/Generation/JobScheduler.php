<?php

namespace App\Generation;

use App\Entity\GenerationJob;
use App\Entity\Novel;
use App\Enum\Step;
use App\Exception\UserFacingException;
use App\Message\RunGenerationJob;
use App\Repository\GenerationJobRepository;
use App\Repository\NovelRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Crée les tâches de génération en garantissant une seule tâche active par roman.
 */
final class JobScheduler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NovelRepository $novels,
        private readonly GenerationJobRepository $jobs,
        private readonly MessageBusInterface $bus,
        private readonly int $maxAttempts,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function schedule(Novel $novel, Step $step, ?int $chapterNumber = null, array $payload = []): GenerationJob
    {
        try {
            $job = $this->em->wrapInTransaction(function () use ($novel, $step, $chapterNumber, $payload): GenerationJob {
                $this->novels->lock($novel);
                if (null !== $this->jobs->findActive($novel)) {
                    throw new UserFacingException('Une étape est déjà en cours pour ce roman. Attendez qu\'elle se termine.');
                }
                $job = new GenerationJob($novel, $step, $chapterNumber, $this->maxAttempts, $payload);
                $this->em->persist($job);
                $this->em->flush();

                return $job;
            });
        } catch (UniqueConstraintViolationException) {
            throw new UserFacingException('Une étape est déjà en cours pour ce roman (double commande ignorée).');
        }

        $this->bus->dispatch(new RunGenerationJob((int) $job->getId()));

        return $job;
    }

    public function redispatch(GenerationJob $job, int $delayMs = 0): void
    {
        $stamps = $delayMs > 0 ? [new DelayStamp($delayMs)] : [];
        $this->bus->dispatch(new RunGenerationJob((int) $job->getId()), $stamps);
    }
}
