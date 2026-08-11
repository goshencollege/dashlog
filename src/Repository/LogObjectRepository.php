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
            ->setParameter('backend', $backend)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }
}
