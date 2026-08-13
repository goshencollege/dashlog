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
    // How many drain cycles' worth of delay to tolerate before the oldest
    // spool object counts as stale, rather than a fixed number of seconds —
    // ties the health page's grace period to SpoolDrainSchedule's actual
    // cadence so the two can't drift out of sync if that cadence changes.
    private const STALE_AFTER_DRAIN_CYCLES = 5;

    public function __construct(
        private readonly StorageBackendRepository $storageBackendRepository,
        private readonly LogObjectRepository $logObjectRepository,
        private readonly LogEntryRepository $logEntryRepository,
        private readonly SpoolProvider $spoolProvider,
        private readonly Connection $connection,
        private readonly int $spoolDrainIntervalSeconds,
    ) {
    }

    /**
     * @return array{
     *     backends: array,
     *     hasActiveBackend: bool,
     *     spoolObjects: LogObject[],
     *     spoolBacklogCount: int,
     *     oldestSpoolObject: LogObject|null,
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

        $spool = $this->spoolProvider->getSpool();
        $spoolObjects = $this->logObjectRepository->findBy(['storageBackend' => $spool], ['createdAt' => 'ASC']);
        $oldestSpoolObject = $spoolObjects[0] ?? null;

        // The spool drains on a regular cadence under normal operation, so
        // a handful of objects sitting there briefly is expected, not an
        // issue — only flag it once the oldest one has outlived several
        // drain cycles' worth of time.
        $staleAfterSeconds = $this->spoolDrainIntervalSeconds * self::STALE_AFTER_DRAIN_CYCLES;
        $staleCutoff = (new \DateTimeImmutable())->modify(sprintf('-%d seconds', $staleAfterSeconds));
        $hasStaleSpoolBacklog = $oldestSpoolObject !== null && $oldestSpoolObject->getCreatedAt() < $staleCutoff;

        $catalogErrors = $this->logObjectRepository->findBy(['status' => 'error']);

        $lastLogEntry = $this->logEntryRepository->findBy([], ['createdAt' => 'DESC'], 1)[0] ?? null;

        $failedMessageCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue',
            ['queue' => 'failed'],
        );

        return [
            'backends' => $backends,
            'hasActiveBackend' => $hasActiveBackend,
            'spoolObjects' => $spoolObjects,
            'spoolBacklogCount' => count($spoolObjects),
            'oldestSpoolObject' => $oldestSpoolObject,
            'hasStaleSpoolBacklog' => $hasStaleSpoolBacklog,
            'catalogErrors' => $catalogErrors,
            'lastLogEntry' => $lastLogEntry,
            'failedMessageCount' => $failedMessageCount,
        ];
    }
}
