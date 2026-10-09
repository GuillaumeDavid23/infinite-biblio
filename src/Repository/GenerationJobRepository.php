<?php

namespace App\Repository;

use App\Entity\GenerationJob;
use App\Entity\Novel;
use App\Enum\JobStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GenerationJob> */
class GenerationJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GenerationJob::class);
    }

    public function findActive(Novel $novel): ?GenerationJob
    {
        return $this->findOneBy(['activeNovelId' => $novel->getId()]);
    }

    /**
     * Passe atomiquement une tâche de « en attente » à « en cours ».
     * Renvoie false si une autre exécution l'a déjà prise (message reçu deux fois).
     */
    public function claim(int $jobId): bool
    {
        $affected = $this->getEntityManager()->createQuery(
            'UPDATE '.GenerationJob::class.' j SET j.status = :running, j.attempts = j.attempts + 1, j.updatedAt = :now
             WHERE j.id = :id AND j.status = :queued'
        )->setParameters([
            'running' => JobStatus::Running,
            'queued' => JobStatus::Queued,
            'now' => new \DateTimeImmutable(),
            'id' => $jobId,
        ])->execute();

        return 1 === $affected;
    }

    /** @return list<GenerationJob> */
    public function findStaleRunning(Novel $novel, \DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('j')
            ->andWhere('j.novel = :novel')->setParameter('novel', $novel)
            ->andWhere('j.status IN (:st)')->setParameter('st', [JobStatus::Running, JobStatus::Queued])
            ->andWhere('j.updatedAt < :before')->setParameter('before', $before)
            ->getQuery()->getResult();
    }

    /** @return list<GenerationJob> */
    public function findRecent(Novel $novel, int $limit = 15): array
    {
        return $this->findBy(['novel' => $novel], ['id' => 'DESC'], $limit);
    }
}
