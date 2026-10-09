<?php

namespace App\Repository;

use App\Entity\Novel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Novel> */
class NovelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Novel::class);
    }

    /** Verrouille la ligne du roman jusqu'à la fin de la transaction en cours. */
    public function lock(Novel $novel): void
    {
        $this->getEntityManager()->lock($novel, LockMode::PESSIMISTIC_WRITE);
        $this->getEntityManager()->refresh($novel);
    }

    /** @return list<Novel> */
    public function findAllRecentFirst(): array
    {
        return $this->createQueryBuilder('n')->orderBy('n.updatedAt', 'DESC')->getQuery()->getResult();
    }
}
