<?php

namespace App\Repository;

use App\Entity\LogObject;
use App\Entity\StorageBackend;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LogObjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LogObject::class);
    }

    public function findOneByBackendAndKey(StorageBackend $backend, string $objectKey): ?LogObject
    {
        return $this->findOneBy(['storageBackend' => $backend, 'objectKey' => $objectKey]);
    }

    /** @return LogObject[] */
    public function findEligibleForTiering(StorageBackend $backend, \DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.storageBackend = :backend')
            ->andWhere('l.windowEnd < :cutoff')
            ->andWhere('l.status != :pending')
            ->setParameter('backend', $backend)
            ->setParameter('cutoff', $cutoff)
            ->setParameter('pending', 'pending')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything on a backend that actually has bytes written — excludes
     * 'pending' objects (entries recorded for visibility, but nothing
     * written to storage yet; there's nothing there to migrate).
     *
     * @return LogObject[]
     */
    public function findMovableOnBackend(StorageBackend $backend): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.storageBackend = :backend')
            ->andWhere('l.status != :pending')
            ->setParameter('backend', $backend)
            ->setParameter('pending', 'pending')
            ->orderBy('l.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
