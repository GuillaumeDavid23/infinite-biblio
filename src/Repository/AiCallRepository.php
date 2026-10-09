<?php

namespace App\Repository;

use App\Entity\AiCall;
use App\Entity\Novel;
use App\Enum\AiCallStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AiCall> */
class AiCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiCall::class);
    }

    /** @return list<AiCall> */
    public function findForNovel(Novel $novel): array
    {
        return $this->findBy(['novel' => $novel], ['id' => 'ASC']);
    }

    /**
     * Coût constaté cumulé (tous appels, y compris brouillons, erreurs et corrections abandonnées).
     */
    public function sumCost(Novel $novel, bool $simulated): int
    {
        return (int) $this->createQueryBuilder('a')->select('COALESCE(SUM(a.costPico), 0)')
            ->andWhere('a.novel = :novel')->setParameter('novel', $novel)
            ->andWhere('a.simulated = :sim')->setParameter('sim', $simulated)
            ->getQuery()->getSingleScalarResult();
    }

    /** Plafonds réservés par les appels encore en cours (coût pas encore connu). */
    public function sumPendingCeilings(Novel $novel, bool $simulated): int
    {
        return (int) $this->createQueryBuilder('a')->select('COALESCE(SUM(a.ceilingPico), 0)')
            ->andWhere('a.novel = :novel')->setParameter('novel', $novel)
            ->andWhere('a.simulated = :sim')->setParameter('sim', $simulated)
            ->andWhere('a.status = :pending')->setParameter('pending', AiCallStatus::Pending)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<AiCall> */
    public function findPending(Novel $novel): array
    {
        return $this->findBy(['novel' => $novel, 'status' => AiCallStatus::Pending]);
    }

    public function countCallsWithUnknownCost(Novel $novel): int
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.novel = :novel')->setParameter('novel', $novel)
            ->andWhere('a.costPico IS NULL')
            ->getQuery()->getSingleScalarResult();
    }
}
