<?php

namespace App\Service;

use App\Entity\JobRun;
use App\Repository\JobRunRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records each scheduled job's most recent outcome for the health page.
 * Deliberately re-throws on failure — this only observes, it must never
 * swallow an exception Messenger's own retry/failed-transport handling
 * needs to see.
 */
class JobRunTracker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JobRunRepository $jobRunRepository,
    ) {
    }

    public function track(string $jobName, \DateTimeImmutable $now, callable $job): void
    {
        try {
            $job();
            $this->record($jobName, $now, 'success', null);
        } catch (\Throwable $e) {
            $this->record($jobName, $now, 'error', $e->getMessage());

            throw $e;
        }
    }

    private function record(string $jobName, \DateTimeImmutable $now, string $status, ?string $error): void
    {
        $jobRun = $this->jobRunRepository->findOneBy(['jobName' => $jobName]) ?? new JobRun($jobName);
        $jobRun->setLastRunAt($now);
        $jobRun->setStatus($status);
        $jobRun->setLastError($error);

        $this->em->persist($jobRun);
        $this->em->flush();
    }
}
