<?php

namespace App\Service;

use App\Entity\LogObject;
use App\Entity\StorageBackend;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Moves a single LogObject from its current backend to another one, used by
 * both the scheduled tiering sweep (age-based) and the manual
 * app:log-objects:migrate command (hardware replacement, etc).
 *
 * Read → write destination → verify → flip the catalog row → delete source,
 * in that order, so a crash at any point leaves the object still readable
 * from wherever it currently, correctly, points — never a dangling
 * reference and never a silent loss. Re-running migrate() on a
 * partially-migrated object is safe: it just repeats the copy.
 *
 * Called from the scheduled tiering/spool-drain sweeps, which loop over
 * many hundreds of objects in one call — if any single flush fails for any
 * reason (a dropped DB connection, a deadlock), Doctrine closes that
 * EntityManager permanently, and every later object in the same sweep
 * would otherwise fail too. entityManager() resolves a fresh one via
 * ManagerRegistry when that happens (same fix as LogBatchWriter's, for the
 * same reason), and $logObject/$destination are re-fetched through it so
 * they're never operated on while detached from whichever manager is
 * actually current.
 */
class LogObjectMigrationService
{
    public function __construct(
        private readonly StorageService $storageService,
        private readonly KeyScheme $keyScheme,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    public function migrate(LogObject $logObject, StorageBackend $destination): void
    {
        $em = $this->entityManager();
        $logObject = $em->find(LogObject::class, $logObject->getId()) ?? $logObject;
        $destination = $em->find(StorageBackend::class, $destination->getId()) ?? $destination;

        if ($logObject->getStatus() === 'pending') {
            // Entries recorded for visibility, but no bytes written to
            // storage yet — there's nothing to copy. Callers are expected to
            // filter these out already (LogObjectRepository::findMovableOnBackend());
            // this is a defensive backstop, not the primary guard.
            throw new \LogicException(sprintf(
                'Cannot migrate log object "%s": it is still pending, nothing has been written to storage for it yet.',
                $logObject->getObjectKey(),
            ));
        }

        $source = $logObject->getStorageBackend();
        if ($source->getId() === $destination->getId()) {
            return;
        }

        $key = $logObject->getObjectKey();
        $metaKey = $this->keyScheme->metaKeyFor($key);

        $logObject->setStatus('migrating');
        $em->flush();

        try {
            $content = $this->storageService->read($source, $key);
            $meta = $this->storageService->read($source, $metaKey);

            $this->storageService->write($destination, $key, $content);
            $this->storageService->write($destination, $metaKey, $meta);

            // Re-read from the destination rather than trusting $content —
            // this catches both a bad write and pre-existing corruption at
            // the source, and we must not delete the source copy until
            // we're sure a good copy exists elsewhere.
            $verifyChecksum = hash('sha256', $this->storageService->read($destination, $key));
            if ($verifyChecksum !== $logObject->getChecksumSha256()) {
                throw new \RuntimeException(sprintf(
                    'Checksum mismatch after migrating "%s" to "%s" (expected %s, got %s).',
                    $key,
                    $destination->getName(),
                    (string) $logObject->getChecksumSha256(),
                    $verifyChecksum,
                ));
            }

            $logObject->setStorageBackend($destination);
            $logObject->setTierRank($destination->getTierRank());
            $logObject->setStatus('stored');
            $logObject->setLastError(null);
            $em->flush();

            $this->storageService->delete($source, $key);
            $this->storageService->delete($source, $metaKey);
        } catch (\Throwable $e) {
            $logObject->setStatus('error');
            $logObject->setLastError($e->getMessage());
            $em->flush();

            throw $e;
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->doctrine->getManager();

        return $em->isOpen() ? $em : $this->doctrine->resetManager();
    }
}
