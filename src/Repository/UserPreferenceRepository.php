<?php

namespace App\Repository;

use App\Entity\UserPreference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserPreference::class);
    }

    public function findByIdentifier(string $identifier): ?UserPreference
    {
        return $this->findOneBy(['userIdentifier' => $identifier]);
    }
}
