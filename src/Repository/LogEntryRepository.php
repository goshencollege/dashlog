<?php

namespace App\Repository;

use App\Entity\LogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LogEntry::class);
    }

    /**
     * @param array{source?: string, severity?: int, message?: string, from?: \DateTimeImmutable, to?: \DateTimeImmutable} $filters
     * @return array{results: LogEntry[], total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('l')->orderBy('l.timestamp', 'DESC');

        if (($filters['source'] ?? '') !== '') {
            $qb->andWhere('l.source LIKE :source')->setParameter('source', '%' . $filters['source'] . '%');
        }
        if (isset($filters['severity'])) {
            $qb->andWhere('l.severity = :severity')->setParameter('severity', $filters['severity']);
        }
        if (($filters['message'] ?? '') !== '') {
            $qb->andWhere('l.message LIKE :message')->setParameter('message', '%' . $filters['message'] . '%');
        }
        if (isset($filters['from'])) {
            $qb->andWhere('l.timestamp >= :from')->setParameter('from', $filters['from']);
        }
        if (isset($filters['to'])) {
            $qb->andWhere('l.timestamp <= :to')->setParameter('to', $filters['to']);
        }

        $total = (int) (clone $qb)->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();

        $results = $qb
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['results' => $results, 'total' => $total];
    }
}
