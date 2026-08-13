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

        // 'pending' objects aren't part of the drain backlog at all —
        // SpoolDrainService never touches them either (see its docblock):
        // their batch window is still open and there are no bytes to move
        // yet. A LogObject's createdAt is set when its window *opens*, so
        // one can already be up to a full batch window old by the time it
        // closes and the object even becomes eligible to drain — using
        // createdAt for staleness here would flag perfectly healthy
        // objects well before the drain ever gets a chance at them.
        // updatedAt instead reflects when it last changed state (e.g. the
        // pending → staged transition), which is what "how long has this
        // actually been waiting" needs to measure.
        $spoolObjects = $this->logObjectRepository->findMovableOnBackend($spool);
        $oldestSpoolObject = $this->oldestByUpdatedAt($spoolObjects);

        // The spool drains on a regular cadence under normal operation, so
        // a handful of objects sitting there briefly is expected, not an
        // issue — only flag it once the oldest one has outlived several
        // drain cycles' worth of time.
        $staleAfterSeconds = $this->spoolDrainIntervalSeconds * self::STALE_AFTER_DRAIN_CYCLES;
        $staleCutoff = (new \DateTimeImmutable())->modify(sprintf('-%d seconds', $staleAfterSeconds));
        $hasStaleSpoolBacklog = $oldestSpoolObject !== null && $oldestSpoolObject->getUpdatedAt() < $staleCutoff;

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

    /**
     * @param LogObject[] $spoolObjects
     */
    private function oldestByUpdatedAt(array $spoolObjects): ?LogObject
    {
        if ($spoolObjects === []) {
            return null;
        }

        usort($spoolObjects, static fn (LogObject $a, LogObject $b) => $a->getUpdatedAt() <=> $b->getUpdatedAt());

        return $spoolObjects[0];
    }
}
