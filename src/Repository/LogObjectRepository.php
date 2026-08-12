<?php

namespace App\Repository;

use App\Entity\LogObject;
use App\Entity\StorageBackend;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LogObjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LogObject::class);
    }

    public function findOneByBackendAndKey(StorageBackend $backend, string $objectKey): ?LogObject
    {
        return $this->findOneBy(['storageBackend' => $backend, 'objectKey' => $objectKey]);
    }

    /**
     * The catalog row for a batch, identified by (source, windowStart)
     * rather than by its current backend/key — a batch's canonical key is
     * fixed at creation, but it can move backends (spool → real, or
     * between tiers) over its lifetime, and a late-arriving line for the
     * same window must find it wherever it currently lives, not just
     * while it's still on the spool.
     */
    public function findOneBySourceAndWindowStart(string $source, \DateTimeImmutable $windowStart): ?LogObject
    {
        return $this->findOneBy(['source' => $source, 'windowStart' => $windowStart]);
    }

    /** @return LogObject[] */
    public function findEligibleForTiering(StorageBackend $backend, \DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.storageBackend = :backend')
            ->andWhere('l.windowEnd < :cutoff')
            ->andWhere('l.status != :pending')
            ->setParameter('backend', $backend)
            ->setParameter('cutoff', $cutoff)
            ->setParameter('pending', 'pending')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything on a backend that actually has bytes written — excludes
     * 'pending' objects (entries recorded for visibility, but nothing
     * written to storage yet; there's nothing there to migrate).
     *
     * @return LogObject[]
     */
    public function findMovableOnBackend(StorageBackend $backend): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.storageBackend = :backend')
            ->andWhere('l.status != :pending')
            ->setParameter('backend', $backend)
            ->setParameter('pending', 'pending')
            ->orderBy('l.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Objects overlapping [$from, $to] (either end open) whose LogEntry
     * rows have already been pruned — i.e. the only way to see their
     * content now is by reading it back from storage. Used by
     * LogBrowseCoordinator to decide whether a browse/search query needs
     * the archive fallback at all.
     *
     * @param string[] $sources
     * @return LogObject[]
     */
    public function findUncachedOverlapping(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, array $sources = []): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.entriesCached = false');

        if ($from !== null) {
            $qb->andWhere('l.windowEnd >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('l.windowStart <= :to')->setParameter('to', $to);
        }
        if ($sources !== []) {
            $qb->andWhere('l.source IN (:sources)')->setParameter('sources', $sources);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * 'pending' objects whose window closed well before $cutoff. LogIngestor
     * tracks open windows only in process memory (see its docblock) — one
     * can be abandoned forever if the process dies before finalizing it.
     * Used by OrphanedLogObjectFinalizer as a safety net for exactly that.
     *
     * @return LogObject[]
     */
    public function findStalePending(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.status = :pending')
            ->andWhere('l.windowEnd < :cutoff')
            ->setParameter('pending', 'pending')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }
}
