<?php

namespace App\Service;

use App\Entity\LogObject;
use App\Repository\LogEntryRepository;
use App\Repository\LogObjectRepository;
use App\Repository\StorageBackendRepository;
use Doctrine\DBAL\Connection;

/**
 * Gathers a snapshot of operational health for the admin health page.
 * Deliberately derived entirely from existing data (no active probing) —
 * a backend's shown connectivity status is only as fresh as the last time
 * it was tested via the storage backend admin page.
 */
class HealthCheckService
{
    // How many drain cycles' worth of delay to tolerate before a staged/
    // error spool object counts as stale, rather than a fixed number of
    // seconds — ties the health page's grace period to SpoolDrainSchedule's
    // actual cadence so the two can't drift out of sync if that cadence
    // changes.
    private const STALE_AFTER_DRAIN_CYCLES = 5;

    public function __construct(
        private readonly StorageBackendRepository $storageBackendRepository,
        private readonly LogObjectRepository $logObjectRepository,
        private readonly LogEntryRepository $logEntryRepository,
        private readonly SpoolProvider $spoolProvider,
        private readonly Connection $connection,
        private readonly int $spoolDrainIntervalSeconds,
        private readonly int $pendingStaleSeconds,
    ) {
    }

    /**
     * @return array{
     *     backends: array,
     *     hasActiveBackend: bool,
     *     spoolObjects: array<array{object: LogObject, since: \DateTimeImmutable, isStale: bool}>,
     *     spoolBacklogCount: int,
     *     staleSpoolBacklogCount: int,
     *     hasStaleSpoolBacklog: bool,
     *     catalogErrors: LogObject[],
     *     lastLogEntry: \App\Entity\LogEntry|null,
     *     failedMessageCount: int,
     * }
     */
    public function check(): array
    {
        $backends = $this->storageBackendRepository->findAllExcludingSpool();
        $hasActiveBackend = $this->storageBackendRepository->findActiveOrderedByTier() !== [];

        // Every object currently on the spool, regardless of status —
        // including 'pending' ones, so a real incident (e.g. a crashed
        // listener leaving windows stuck open) is still visible here
        // rather than hidden from the list entirely.
        $spool = $this->spoolProvider->getSpool();
        $spoolObjects = $this->logObjectRepository->findBy(['storageBackend' => $spool], ['createdAt' => 'ASC']);

        $now = new \DateTimeImmutable();
        $spoolObjectRows = array_map(fn (LogObject $object) => $this->describeSpoolObject($object, $now), $spoolObjects);
        $staleSpoolBacklogCount = count(array_filter($spoolObjectRows, static fn (array $row) => $row['isStale']));

        $catalogErrors = $this->logObjectRepository->findBy(['status' => 'error']);

        $lastLogEntry = $this->logEntryRepository->findBy([], ['createdAt' => 'DESC'], 1)[0] ?? null;

        $failedMessageCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue',
            ['queue' => 'failed'],
        );

        return [
            'backends' => $backends,
            'hasActiveBackend' => $hasActiveBackend,
            'spoolObjects' => $spoolObjectRows,
            'spoolBacklogCount' => count($spoolObjectRows),
            'staleSpoolBacklogCount' => $staleSpoolBacklogCount,
            'hasStaleSpoolBacklog' => $staleSpoolBacklogCount > 0,
            'catalogErrors' => $catalogErrors,
            'lastLogEntry' => $lastLogEntry,
            'failedMessageCount' => $failedMessageCount,
        ];
    }

    /**
     * @return array{object: LogObject, since: \DateTimeImmutable, isStale: bool}
     */
    private function describeSpoolObject(LogObject $object, \DateTimeImmutable $now): array
    {
        // 'pending' objects (window still open, nothing written yet — see
        // SpoolDrainService's docblock) are expected to sit here for up to
        // a full batch window; only worth flagging once they've outlived
        // that window by the same margin OrphanedLogObjectFinalizer uses to
        // treat one as orphaned, since that's this app's one existing
        // definition of "a pending object has been open too long."
        // Anything else (staged/error) should already be moving on a
        // regular drain cadence, so "since" is when it last changed state.
        if ($object->getStatus() === 'pending') {
            $since = $object->getWindowEnd();
            $cutoff = $now->modify(sprintf('-%d seconds', $this->pendingStaleSeconds));
        } else {
            $since = $object->getUpdatedAt();
            $cutoff = $now->modify(sprintf('-%d seconds', $this->spoolDrainIntervalSeconds * self::STALE_AFTER_DRAIN_CYCLES));
        }

        return [
            'object' => $object,
            'since' => $since,
            'isStale' => $since < $cutoff,
        ];
    }
}
