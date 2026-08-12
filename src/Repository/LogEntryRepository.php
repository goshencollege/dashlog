<?php

namespace App\Repository;

use App\Entity\LogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class LogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LogEntry::class);
    }

    /**
     * @param array{source?: string[], severity?: int[], message?: string, from?: \DateTimeImmutable, to?: \DateTimeImmutable} $filters
     * @return array{results: LogEntry[], total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('l')->orderBy('l.timestamp', 'DESC');
        $this->applyFilters($qb, $filters);

        $total = (int) (clone $qb)->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();

        $results = $qb
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['results' => $results, 'total' => $total];
    }

    /**
     * Entries newer than $sinceId matching the given filters, oldest first
     * (the order a live-tail poller should insert them in) — for the
     * browse page's "Live" polling toggle. Capped so a client that resumes
     * after a long pause (backgrounded tab, etc.) can't pull down an
     * unbounded backlog in one request; it'll just take a few more polls to
     * catch up.
     *
     * @param array{source?: string[], severity?: int[], message?: string, from?: \DateTimeImmutable, to?: \DateTimeImmutable} $filters
     * @return LogEntry[]
     */
    public function findNewerThan(int $sinceId, array $filters, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('l')
            ->andWhere('l.id > :sinceId')
            ->setParameter('sinceId', $sinceId)
            ->orderBy('l.id', 'ASC')
            ->setMaxResults($limit);
        $this->applyFilters($qb, $filters);

        return $qb->getQuery()->getResult();
    }

    /**
     * Every LogEntry matching filters, unpaginated (capped at $limit) and
     * newest first — used by LogBrowseCoordinator to merge with archive
     * results when part of a query's range has been pruned. Normal
     * browsing should use search() instead; this exists only for that
     * merge path, where both sources need to be sorted/paginated together
     * in PHP rather than at the DB level.
     *
     * @param array{source?: string[], severity?: int[], message?: string, from?: \DateTimeImmutable, to?: \DateTimeImmutable} $filters
     * @return LogEntry[]
     */
    public function findAllMatching(array $filters, int $limit): array
    {
        $qb = $this->createQueryBuilder('l')
            ->orderBy('l.timestamp', 'DESC')
            ->setMaxResults($limit);
        $this->applyFilters($qb, $filters);

        return $qb->getQuery()->getResult();
    }

    /** @param array{source?: string[], severity?: int[], message?: string, from?: \DateTimeImmutable, to?: \DateTimeImmutable} $filters */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (($filters['source'] ?? []) !== []) {
            $qb->andWhere('l.source IN (:sources)')->setParameter('sources', $filters['source']);
        }
        if (($filters['severity'] ?? []) !== []) {
            $qb->andWhere('l.severity IN (:severities)')->setParameter('severities', $filters['severity']);
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
    }

    /**
     * Distinct known sources matching a substring, for the browse page's
     * source multi-select to search against — capped since a network could
     * plausibly have hundreds of distinct sources.
     *
     * @return string[]
     */
    public function findDistinctSources(string $query, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('l')
            ->select('DISTINCT l.source')
            ->orderBy('l.source', 'ASC')
            ->setMaxResults($limit);

        if ($query !== '') {
            $qb->andWhere('l.source LIKE :query')->setParameter('query', '%' . $query . '%');
        }

        return array_column($qb->getQuery()->getScalarResult(), 'source');
    }
}
