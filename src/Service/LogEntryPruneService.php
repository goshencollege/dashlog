<?php

namespace App\Service;

use App\Entity\LogEntry;
use App\Entity\LogObject;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps log_entry from growing forever: log_entry is a derived search
 * index, droppable and rebuildable by re-reading LogObjects (see
 * LogEntry's own docblock) — so once a batch is old enough and durably
 * written to a real backend, its LogEntry rows can be deleted and the
 * batch's LogObject flagged as no longer cached. LogBrowseCoordinator
 * checks that flag to fall back to reading the batch file directly
 * instead of assuming anything about retentionDays or scheduler timing.
 *
 * Operates at LogObject (batch) granularity rather than per-row, since
 * that's the unit entriesCached and every other lifecycle field already
 * live at.
 */
class LogEntryPruneService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly int $retentionDays,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(\DateTimeImmutable $now): int
    {
        if ($this->retentionDays <= 0) {
            return 0;
        }

        $cutoff = $now->modify(sprintf('-%d days', $this->retentionDays));

        $ids = array_column($this->em->createQueryBuilder()
            ->select('o.id')
            ->from(LogObject::class, 'o')
            ->where('o.status = :stored')
            ->andWhere('o.entriesCached = true')
            ->andWhere('o.windowEnd < :cutoff')
            ->setParameter('stored', 'stored')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getScalarResult(), 'id');

        if ($ids === []) {
            return 0;
        }

        $deletedRows = $this->em->createQueryBuilder()
            ->delete(LogEntry::class, 'e')
            ->where('e.logObject IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();

        $this->em->createQueryBuilder()
            ->update(LogObject::class, 'o')
            ->set('o.entriesCached', ':cached')
            ->where('o.id IN (:ids)')
            ->setParameter('cached', false)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();

        $this->logger->info('Pruned old log entries now only readable from storage.', [
            'logObjectCount' => count($ids),
            'entryRowCount' => $deletedRows,
            'cutoffAt' => $cutoff->format(\DateTimeInterface::ATOM),
        ]);

        return $deletedRows;
    }
}
