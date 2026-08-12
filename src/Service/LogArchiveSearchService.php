<?php

namespace App\Service;

use App\Entity\LogEntry;
use App\Entity\LogObject;
use Psr\Log\LoggerInterface;

/**
 * Reconstructs LogEntry-shaped results directly from a LogObject's stored
 * batch file, for browse/search queries that reach data LogEntryPruneService
 * has already removed from the DB. The returned LogEntry instances are
 * transient DTOs — never persisted — built only so the existing dashboard
 * template (which reads entry.timestamp/severity/source/appName/procId/
 * facility/message) can render them without any template changes.
 *
 * These are files on external stores (CIFS shares, S3-compatible buckets)
 * that can go missing or become unreachable independently of what our own
 * catalog thinks — see StorageService::write()'s read-back-retry handling
 * and the admin health page's backend-connectivity checks for the same
 * assumption elsewhere. So every object is read in its own try/catch: one
 * bad or externally-deleted file must never take down the whole browse.
 */
class LogArchiveSearchService
{
    public function __construct(
        private readonly StorageService $storageService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param LogObject[] $objects
     * @param array{source?: string[], severity?: int[], message?: string, from?: \DateTimeImmutable, to?: \DateTimeImmutable} $filters
     * @return LogEntry[] unsorted — caller merges/sorts alongside any DB results
     */
    public function searchObjects(array $objects, array $filters): array
    {
        $entries = [];

        foreach ($objects as $object) {
            foreach ($this->readEntries($object) as $entry) {
                if ($this->matchesFilters($entry, $filters)) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /** @return LogEntry[] */
    private function readEntries(LogObject $object): array
    {
        try {
            $raw = gzdecode($this->storageService->read($object->getStorageBackend(), $object->getObjectKey()));
            if ($raw === false) {
                throw new \RuntimeException('gzdecode failed');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Skipping a LogObject in archive search: could not read/decompress its stored content.', [
                'logObjectId' => $object->getId(),
                'objectKey' => $object->getObjectKey(),
                'backendId' => $object->getStorageBackend()->getId(),
                'exception' => $e,
            ]);

            return [];
        }

        $entries = [];
        foreach (explode("\n", trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }

            $entry = new LogEntry();
            $entry->setLogObject($object);
            $entry->setSource($data['source'] ?? $object->getSource());
            $entry->setTimestamp(new \DateTimeImmutable($data['timestamp']));
            $entry->setHost($data['host'] ?? null);
            $entry->setAppName($data['appName'] ?? null);
            $entry->setProcId($data['procId'] ?? null);
            $entry->setSeverity($data['severity'] ?? null);
            $entry->setFacility($data['facility'] ?? null);
            $entry->setMessage($data['message'] ?? '');
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @param array{source?: string[], severity?: int[], message?: string, from?: \DateTimeImmutable, to?: \DateTimeImmutable} $filters */
    private function matchesFilters(LogEntry $entry, array $filters): bool
    {
        if (($filters['severity'] ?? []) !== [] && !in_array($entry->getSeverity(), $filters['severity'], true)) {
            return false;
        }
        if (($filters['message'] ?? '') !== '' && !str_contains(strtolower($entry->getMessage()), strtolower($filters['message']))) {
            return false;
        }
        if (isset($filters['from']) && $entry->getTimestamp() < $filters['from']) {
            return false;
        }
        if (isset($filters['to']) && $entry->getTimestamp() > $filters['to']) {
            return false;
        }

        return true;
    }
}
