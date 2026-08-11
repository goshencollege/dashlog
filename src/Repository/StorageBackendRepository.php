<?php

namespace App\Repository;

use App\Entity\StorageBackend;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class StorageBackendRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StorageBackend::class);
    }

    /** @return StorageBackend[] */
    public function findActive(): array
    {
        return $this->findBy(['isActive' => true]);
    }

    /** @return StorageBackend[] hottest (lowest tierRank) first, excluding the write-ahead spool */
    public function findActiveOrderedByTier(): array
    {
        return $this->findBy(['isActive' => true, 'isSpool' => false], ['tierRank' => 'ASC']);
    }

    /** @return StorageBackend[] admin-manageable backends — the system write-ahead spool is not shown here */
    public function findAllExcludingSpool(): array
    {
        return $this->findBy(['isSpool' => false]);
    }
}
