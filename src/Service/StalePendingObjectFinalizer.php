<?php

namespace App\Service;

use App\Dto\IngestedLogLine;
use App\Entity\LogEntry;
use App\Entity\LogObject;
use App\Repository\LogEntryRepository;
use App\Repository\LogObjectRepository;
use Psr\Log\LoggerInterface;

/**
 * Safety net for LogIngestor's documented limitation: its per-source window
 * buffer lives only in process memory, so a syslog listener that dies
 * before a window closes — a SIGKILL, an OOM-kill, anything a graceful
 * SIGTERM handler can't catch — leaves that window's LogObject stuck
 * 'pending' forever. Its LogEntry rows are already safely recorded (they're
 * written independently, for immediate visibility), but nothing ever
 * writes the actual batch file or flips the catalog row out of 'pending'.
 *
 * Finds any 'pending' object whose window closed long ago — far longer
 * than any healthy process would ever leave one open — and finalizes it
 * via LogBatchWriter, reconstructing the batch's lines from its own
 * already-recorded LogEntry rows rather than needing the original
 * in-memory buffer.
 */
class StalePendingObjectFinalizer
{
    private const STALE_AFTER_SECONDS = 3600;

    public function __construct(
        private readonly LogObjectRepository $logObjectRepository,
        private readonly LogEntryRepository $logEntryRepository,
        private readonly LogBatchWriter $batchWriter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(\DateTimeImmutable $now): int
    {
        $cutoff = $now->modify(sprintf('-%d seconds', self::STALE_AFTER_SECONDS));
        $stale = $this->logObjectRepository->findStalePending($cutoff);

        $finalized = 0;
        foreach ($stale as $logObject) {
            try {
                $entries = $this->logEntryRepository->findByLogObject($logObject);
                if ($entries === []) {
                    $this->logger->warning('Stale pending LogObject has no LogEntry rows to reconstruct from, skipping.', [
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
                $this->logger->error('Failed to finalize a stale pending log object, will retry next sweep.', [
                    'logObjectId' => $logObject->getId(),
                    'objectKey' => $logObject->getObjectKey(),
                    'exception' => $e,
                ]);
            }
        }

        return $finalized;
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
