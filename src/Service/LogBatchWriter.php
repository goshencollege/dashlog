<?php

namespace App\Service;

use App\Dto\IngestedLogLine;
use App\Entity\LogEntry;
use App\Entity\LogObject;
use App\Repository\StorageBackendRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes one closed batching window to storage: the gzipped ndjson object,
 * its meta.json sidecar, the LogObject catalog row, and one LogEntry per
 * line for structured search/browse.
 */
class LogBatchWriter
{
    public function __construct(
        private readonly StorageBackendRepository $storageBackendRepository,
        private readonly StorageService $storageService,
        private readonly KeyScheme $keyScheme,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param IngestedLogLine[] $lines */
    public function write(string $source, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $backend = $this->storageBackendRepository->findActiveOrderedByTier()[0] ?? null;
        if ($backend === null) {
            throw new \RuntimeException('No active storage backend is configured; cannot write log batch.');
        }

        $content = '';
        foreach ($lines as $line) {
            $content .= json_encode($line->toArray(), JSON_THROW_ON_ERROR) . "\n";
        }
        $gzipped = gzencode($content, 9);

        $key = $this->keyScheme->build($source, $windowStart);
        $checksum = hash('sha256', $gzipped);

        $this->storageService->write($backend, $key, $gzipped);

        $meta = [
            'source' => $source,
            'windowStart' => $windowStart->format(\DateTimeInterface::ATOM),
            'windowEnd' => $windowEnd->format(\DateTimeInterface::ATOM),
            'entryCount' => count($lines),
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

        $logObject = new LogObject();
        $logObject->setStorageBackend($backend);
        $logObject->setObjectKey($key);
        $logObject->setSource($source);
        $logObject->setTierRank($backend->getTierRank());
        $logObject->setWindowStart($windowStart);
        $logObject->setWindowEnd($windowEnd);
        $logObject->setSizeBytes(strlen($gzipped));
        $logObject->setChecksumSha256($checksum);
        $logObject->setEntryCount(count($lines));
        $logObject->setStatus('stored');
        $this->em->persist($logObject);

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
            $this->em->persist($entry);
        }

        $this->em->flush();
    }
}
