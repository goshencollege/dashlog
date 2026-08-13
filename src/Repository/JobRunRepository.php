<?php

namespace App\Repository;

use App\Entity\JobRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class JobRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobRun::class);
    }

    /** @return array<string, JobRun> keyed by job name */
    public function findAllIndexedByJobName(): array
    {
        $indexed = [];
        foreach ($this->findAll() as $jobRun) {
            $indexed[$jobRun->getJobName()] = $jobRun;
        }

        return $indexed;
    }
}
