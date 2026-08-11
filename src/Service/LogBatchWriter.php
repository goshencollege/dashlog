<?php

namespace App\Service;

use App\Dto\IngestedLogLine;
use App\Entity\LogEntry;
use App\Entity\LogObject;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Two responsibilities, kept deliberately separate:
 *
 *  - recordEntries(): makes lines visible for browse/search immediately,
 *    without waiting for their window to close. Creates a 'pending'
 *    LogObject shell on first use (no bytes written yet) and a LogEntry
 *    per line. Called frequently (every few seconds) with whatever's
 *    arrived since the last call.
 *  - finalize(): closes out a window once it's done — builds the gzipped
 *    ndjson object from *all* the window's lines, writes it plus its
 *    meta.json sidecar to the write-ahead spool, and flips the LogObject
 *    from 'pending' to 'staged'. Called once per window, when it closes.
 *
 * Both resolve their storage backend via the same find-or-create-by-
 * (backend, key) logic, since a late-arriving second burst after a window
 * already finalized needs to merge into the same catalog row rather than
 * collide with it — whether that late burst is first seen by
 * recordEntries() or by finalize() depends only on timing.
 *
 * Called from SyslogListenCommand's long-running loop, so it can't rely on
 * a single injected EntityManager staying usable forever: Doctrine closes
 * an EntityManager permanently after any failed flush (a deadlock, a
 * dropped connection, anything), and every later call on that same
 * instance keeps throwing after that. Short-lived requests/commands never
 * notice; a daemon that lives for hours will. See entityManager() below.
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

    /**
     * @param IngestedLogLine[] $lines lines not yet recorded as LogEntry rows
     */
    public function recordEntries(
        string $source,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
        array $lines,
        ?LogObject $logObject,
    ): LogObject {
        $em = $this->entityManager();

        if ($logObject === null) {
            $logObject = $this->findOrCreatePending($em, $source, $windowStart);
        }

        $logObject->setWindowEnd(max($logObject->getWindowEnd(), $windowEnd));
        $logObject->setEntryCount($logObject->getEntryCount() + count($lines));
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

        return $logObject;
    }

    /**
     * @param IngestedLogLine[] $lines every line the window received, not just unrecorded ones —
     *                                 needed to rebuild the object's content from scratch
     */
    public function finalize(
        string $source,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
        array $lines,
        ?LogObject $logObject,
    ): void {
        if ($lines === []) {
            return;
        }

        $em = $this->entityManager();

        if ($logObject === null) {
            $logObject = $this->findOrCreatePending($em, $source, $windowStart);
        }

        $backend = $logObject->getStorageBackend();
        $key = $logObject->getObjectKey();

        $newContent = '';
        foreach ($lines as $line) {
            $newContent .= json_encode($line->toArray(), JSON_THROW_ON_ERROR) . "\n";
        }

        // If this object already has bytes on disk, it's a late second
        // burst reusing an already-finalized window's catalog row — preserve
        // the existing content rather than overwriting it. entryCount
        // already reflects every line across both bursts via
        // recordEntries(), which always runs before finalize() for every
        // line, so it's left untouched here.
        $hasExistingContent = $logObject->getStatus() !== 'pending' && $logObject->getChecksumSha256() !== null;
        $combinedContent = $hasExistingContent
            ? gzdecode($this->storageService->read($backend, $key)) . $newContent
            : $newContent;

        $gzipped = gzencode($combinedContent, 9);
        $checksum = hash('sha256', $gzipped);

        $this->storageService->write($backend, $key, $gzipped);

        $meta = [
            'source' => $source,
            'windowStart' => $logObject->getWindowStart()->format(\DateTimeInterface::ATOM),
            'windowEnd' => $windowEnd->format(\DateTimeInterface::ATOM),
            'entryCount' => $logObject->getEntryCount(),
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
        $logObject->setStatus('staged');
        $em->persist($logObject);
        $em->flush();
    }

    private function findOrCreatePending(EntityManagerInterface $em, string $source, \DateTimeImmutable $windowStart): LogObject
    {
        $backend = $this->spoolProvider->getSpool();
        $key = $this->keyScheme->build($source, $windowStart);

        $logObject = $em->getRepository(LogObject::class)->findOneByBackendAndKey($backend, $key);
        if ($logObject !== null) {
            return $logObject;
        }

        $logObject = new LogObject();
        $logObject->setStorageBackend($backend);
        $logObject->setObjectKey($key);
        $logObject->setSource($source);
        $logObject->setTierRank($backend->getTierRank());
        $logObject->setWindowStart($windowStart);
        $logObject->setWindowEnd($windowStart);
        $logObject->setSizeBytes(0);
        $logObject->setEntryCount(0);
        $logObject->setStatus('pending');
        $em->persist($logObject);
        $em->flush();

        return $logObject;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->doctrine->getManager();

        return $em->isOpen() ? $em : $this->doctrine->resetManager();
    }
}
