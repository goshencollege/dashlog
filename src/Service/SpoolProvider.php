<?php

namespace App\Service;

use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Finds (or, on first use, creates) the system write-ahead spool — a
 * dedicated Local StorageBackend that ingestion always writes to first,
 * decoupling "did the write succeed" from the health of whatever real
 * backend is ultimately supposed to hold the data. SpoolDrainService moves
 * objects off it onto a real backend in the background.
 *
 * Called from both the long-running syslog listener and the (already
 * Messenger-protected) worker's spool-drain sweep, so it resolves its own
 * EntityManager fresh each call rather than caching one — see
 * LogBatchWriter's docblock for why a cached one isn't safe for a daemon
 * that never restarts.
 */
class SpoolProvider
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly string $spoolPath,
    ) {
    }

    public function getSpool(): StorageBackend
    {
        $em = $this->entityManager();

        $spool = $em->getRepository(StorageBackend::class)->findOneBy(['isSpool' => true]);
        if ($spool !== null) {
            return $spool;
        }

        $spool = new StorageBackend();
        $spool->setName('System Write-Ahead Spool');
        $spool->setType(StorageBackendType::Local);
        $spool->setPath($this->spoolPath);
        $spool->setIsActive(true);
        $spool->setIsSpool(true);
        $em->persist($spool);
        $em->flush();

        return $spool;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->doctrine->getManager();

        return $em->isOpen() ? $em : $this->doctrine->resetManager();
    }
}
