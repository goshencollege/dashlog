<?php

namespace App\Service;

use App\Dto\IngestedLogLine;
use App\Entity\LogEntry;
use App\Entity\LogObject;
use App\Repository\LogEntryRepository;
use App\Repository\LogObjectRepository;
use Psr\Log\LoggerInterface;

/**
 * Safety net for two ways a LogObject's catalog row can end up out of sync
 * with what's actually on storage, in both cases while its LogEntry rows
 * (recorded independently, for immediate visibility) stay completely
 * intact:
 *
 *  - 'pending' forever: LogIngestor's per-source window buffer lives only
 *    in process memory (see its docblock), so a syslog listener that dies
 *    before a window closes — a SIGKILL, an OOM-kill, anything a graceful
 *    signal handler can't catch — leaves that window's LogObject stuck
 *    'pending', since nothing ever writes the batch file or flips its
 *    status. Eligible once its window closed long ago — far longer than
 *    any healthy process would ever leave one open.
 *  - 'error' with no readable content: a migration attempt can fail after
 *    its own object's file has already gone missing or unreadable at its
 *    current (backend, key) — e.g. the file was lost outside the
 *    application. Not every 'error' object qualifies, though: the same
 *    status also covers ordinary destination-write failures where the
 *    object's own current file is completely fine and just needs the
 *    scheduled drain to retry it — SpoolDrainService/TieringService
 *    already do that every sweep. So this only touches an 'error' object
 *    if reading it back, right now, actually fails.
 *
 * Both cases get the same fix: reconstruct the batch's lines from its own
 * LogEntry rows and re-finalize via LogBatchWriter, rather than needing
 * the original in-memory buffer or an intact source file.
 */
class OrphanedLogObjectFinalizer
{
    private const STALE_AFTER_SECONDS = 3600;

    public function __construct(
        private readonly LogObjectRepository $logObjectRepository,
        private readonly LogEntryRepository $logEntryRepository,
        private readonly LogBatchWriter $batchWriter,
        private readonly StorageService $storageService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(\DateTimeImmutable $now): int
    {
        $cutoff = $now->modify(sprintf('-%d seconds', self::STALE_AFTER_SECONDS));
        $candidates = [
            ...$this->logObjectRepository->findStalePending($cutoff),
            ...array_filter($this->logObjectRepository->findBy(['status' => 'error']), $this->hasUnreadableContent(...)),
        ];

        $finalized = 0;
        foreach ($candidates as $logObject) {
            try {
                $entries = $this->logEntryRepository->findByLogObject($logObject);
                if ($entries === []) {
                    $this->logger->warning('Orphaned LogObject has no LogEntry rows to reconstruct from, skipping.', [
                        'logObjectId' => $logObject->getId(),
                        'objectKey' => $logObject->getObjectKey(),
                    ]);
                    continue;
                }

                $this->batchWriter->finalize(
                    $logObject->getSource(),
                    $logObject->getWindowStart(),
                    $logObject->getWindowEnd(),
                    array_map($this->toIngestedLogLine(...), $entries),
                    $logObject,
                );
                $finalized++;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to finalize an orphaned log object, will retry next sweep.', [
                    'logObjectId' => $logObject->getId(),
                    'objectKey' => $logObject->getObjectKey(),
                    'exception' => $e,
                ]);
            }
        }

        return $finalized;
    }

    private function hasUnreadableContent(LogObject $logObject): bool
    {
        try {
            $this->storageService->read($logObject->getStorageBackend(), $logObject->getObjectKey());

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    private function toIngestedLogLine(LogEntry $entry): IngestedLogLine
    {
        return new IngestedLogLine(
            source: $entry->getSource(),
            timestamp: $entry->getTimestamp(),
            host: $entry->getHost(),
            appName: $entry->getAppName(),
            procId: $entry->getProcId(),
            severity: $entry->getSeverity(),
            facility: $entry->getFacility(),
            message: $entry->getMessage(),
            // LogEntry never stores the original wire text — this is the
            // one field genuinely not recoverable here; message is the
            // closest available stand-in.
            raw: $entry->getMessage(),
        );
    }
}
