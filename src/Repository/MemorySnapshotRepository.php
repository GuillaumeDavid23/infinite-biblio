<?php

namespace App\Repository;

use App\Entity\MemorySnapshot;
use App\Entity\Novel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MemorySnapshot> */
class MemorySnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemorySnapshot::class);
    }

    public function findValid(Novel $novel, int $afterChapter): ?MemorySnapshot
    {
        return $this->findOneBy(['novel' => $novel, 'afterChapter' => $afterChapter, 'stale' => false], ['id' => 'DESC']);
    }

    /** Dernière mémoire valide strictement avant le chapitre donné. */
    public function findLatestBefore(Novel $novel, int $chapterNumber): ?MemorySnapshot
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.novel = :novel')->setParameter('novel', $novel)
            ->andWhere('m.stale = false')
            ->andWhere('m.afterChapter < :n')->setParameter('n', $chapterNumber)
            ->orderBy('m.afterChapter', 'DESC')->addOrderBy('m.id', 'DESC')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** Marque comme périmées les mémoires à partir du chapitre donné. */
    public function markStaleFrom(Novel $novel, int $fromChapter): int
    {
        return $this->createQueryBuilder('m')
            ->update()->set('m.stale', 'true')
            ->andWhere('m.novel = :novel')->setParameter('novel', $novel)
            ->andWhere('m.afterChapter >= :n')->setParameter('n', $fromChapter)
            ->andWhere('m.stale = false')
            ->getQuery()->execute();
    }
}
