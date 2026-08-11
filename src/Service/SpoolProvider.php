<?php

namespace App\Service;

use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Repository\StorageBackendRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds (or, on first use, creates) the system write-ahead spool — a
 * dedicated Local StorageBackend that ingestion always writes to first,
 * decoupling "did the write succeed" from the health of whatever real
 * backend is ultimately supposed to hold the data. SpoolDrainService moves
 * objects off it onto a real backend in the background.
 */
class SpoolProvider
{
    public function __construct(
        private readonly StorageBackendRepository $storageBackendRepository,
        private readonly EntityManagerInterface $em,
        private readonly string $spoolPath,
    ) {
    }

    public function getSpool(): StorageBackend
    {
        $spool = $this->storageBackendRepository->findOneBy(['isSpool' => true]);
        if ($spool !== null) {
            return $spool;
        }

        $spool = new StorageBackend();
        $spool->setName('System Write-Ahead Spool');
        $spool->setType(StorageBackendType::Local);
        $spool->setPath($this->spoolPath);
        $spool->setIsActive(true);
        $spool->setIsSpool(true);
        $this->em->persist($spool);
        $this->em->flush();

        return $spool;
    }
}
