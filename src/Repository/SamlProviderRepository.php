<?php

namespace App\Repository;

use App\Entity\SamlProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SamlProviderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SamlProvider::class);
    }

    public function findActive(): ?SamlProvider
    {
        return $this->findOneBy(['isActive' => true]);
    }
}
