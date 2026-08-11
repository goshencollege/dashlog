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

    /** @return StorageBackend[] hottest (lowest tierRank) first */
    public function findActiveOrderedByTier(): array
    {
        return $this->findBy(['isActive' => true], ['tierRank' => 'ASC']);
    }
}
