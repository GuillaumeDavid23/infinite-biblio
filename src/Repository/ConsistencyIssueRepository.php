<?php

namespace App\Repository;

use App\Entity\Chapter;
use App\Entity\ConsistencyIssue;
use App\Entity\Novel;
use App\Enum\IssueSeverity;
use App\Enum\IssueStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ConsistencyIssue> */
class ConsistencyIssueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsistencyIssue::class);
    }

    /** @return list<ConsistencyIssue> */
    public function findForNovel(Novel $novel): array
    {
        return $this->createQueryBuilder('i')
            ->join('i.chapter', 'c')
            ->andWhere('c.novel = :novel')->setParameter('novel', $novel)
            ->orderBy('c.number', 'ASC')->addOrderBy('i.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<ConsistencyIssue> */
    public function findForChapter(Chapter $chapter): array
    {
        return $this->findBy(['chapter' => $chapter], ['id' => 'DESC']);
    }

    public function countOpenCritical(Chapter $chapter): int
    {
        return $this->count(['chapter' => $chapter, 'status' => IssueStatus::Open, 'severity' => IssueSeverity::Critical]);
    }

    public function countOpenForNovel(Novel $novel): int
    {
        return (int) $this->createQueryBuilder('i')->select('COUNT(i.id)')
            ->join('i.chapter', 'c')
            ->andWhere('c.novel = :novel')->setParameter('novel', $novel)
            ->andWhere('i.status = :open')->setParameter('open', IssueStatus::Open)
            ->getQuery()->getSingleScalarResult();
    }
}
