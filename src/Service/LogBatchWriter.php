<?php

namespace App\Service;

use App\Dto\IngestedLogLine;
use App\Entity\LogEntry;
use App\Entity\LogObject;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Writes one closed batching window to storage: the gzipped ndjson object,
 * its meta.json sidecar, the LogObject catalog row, and one LogEntry per
 * line for structured search/browse.
 *
 * Writes always land on the write-ahead spool first (status 'staged'), never
 * directly on a "real" backend — this decouples ingestion from the health of
 * whatever backend logs are ultimately meant to live on. SpoolDrainService
 * moves them from there in the background.
 *
 * Called from SyslogListenCommand's long-running loop, so it can't rely on a
 * single injected EntityManager staying usable forever: Doctrine closes an
 * EntityManager permanently after any failed flush (a deadlock, a dropped
 * connection, anything), and every later call on that same instance keeps
 * throwing after that. Short-lived requests/commands never notice; a daemon
 * that lives for hours will. See entityManager() below.
 */
class LogBatchWriter
{
    public function __construct(
        private readonly SpoolProvider $spoolProvider,
        private readonly StorageService $storageService,
        private readonly KeyScheme $keyScheme,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    /** @param IngestedLogLine[] $lines */
    public function write(string $source, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $em = $this->entityManager();
        $backend = $this->spoolProvider->getSpool();
        $key = $this->keyScheme->build($source, $windowStart);

        $newContent = '';
        foreach ($lines as $line) {
            $newContent .= json_encode($line->toArray(), JSON_THROW_ON_ERROR) . "\n";
        }

        /** @var LogObject|null $logObject */
        $logObject = $em->getRepository(LogObject::class)->findOneByBackendAndKey($backend, $key);

        if ($logObject !== null) {
            // A second, later-arriving burst landed in a window that
            // already flushed once (the same source's traffic split across
            // a gap, more likely to straddle a boundary the shorter the
            // batching window is). Append to the existing object instead of
            // overwriting it: silently clobbering the stored bytes while
            // failing to update this row (object_key is unique per backend,
            // so a second insert attempt would just violate that constraint)
            // would leave the catalog's checksum permanently out of sync
            // with what's actually stored — every later migration attempt
            // would then fail the same way forever, comparing real content
            // against stale, wrong bookkeeping.
            $combinedContent = gzdecode($this->storageService->read($backend, $key)) . $newContent;
            $entryCount = $logObject->getEntryCount() + count($lines);
            $windowEnd = max($logObject->getWindowEnd(), $windowEnd);
        } else {
            $logObject = new LogObject();
            $logObject->setStorageBackend($backend);
            $logObject->setObjectKey($key);
            $logObject->setSource($source);
            $logObject->setTierRank($backend->getTierRank());
            $logObject->setWindowStart($windowStart);
            $combinedContent = $newContent;
            $entryCount = count($lines);
        }

        $gzipped = gzencode($combinedContent, 9);
        $checksum = hash('sha256', $gzipped);

        $this->storageService->write($backend, $key, $gzipped);

        $meta = [
            'source' => $source,
            'windowStart' => $logObject->getWindowStart()->format(\DateTimeInterface::ATOM),
            'windowEnd' => $windowEnd->format(\DateTimeInterface::ATOM),
            'entryCount' => $entryCount,
            'sizeBytes' => strlen($gzipped),
            'checksumSha256' => $checksum,
            'format' => 'ndjson.gz',
            'version' => 1,
        ];
        $this->storageService->write(
            $backend,
            $this->keyScheme->metaKeyFor($key),
            json_encode($meta, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $logObject->setWindowEnd($windowEnd);
        $logObject->setSizeBytes(strlen($gzipped));
        $logObject->setChecksumSha256($checksum);
        $logObject->setEntryCount($entryCount);
        $logObject->setStatus('staged');
        $em->persist($logObject);

        foreach ($lines as $line) {
            $entry = new LogEntry();
            $entry->setLogObject($logObject);
            $entry->setSource($source);
            $entry->setTimestamp($line->timestamp);
            $entry->setHost($line->host);
            $entry->setAppName($line->appName);
            $entry->setProcId($line->procId);
            $entry->setSeverity($line->severity);
            $entry->setFacility($line->facility);
            $entry->setMessage($line->message);
            $em->persist($entry);
        }

        $em->flush();
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->doctrine->getManager();

        return $em->isOpen() ? $em : $this->doctrine->resetManager();
    }
}
