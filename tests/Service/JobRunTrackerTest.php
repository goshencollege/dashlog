<?php

namespace App\Tests\Service;

use App\Repository\JobRunRepository;
use App\Service\JobRunTracker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class JobRunTrackerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private JobRunRepository $jobRunRepository;
    private JobRunTracker $jobRunTracker;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->jobRunRepository = self::getContainer()->get(JobRunRepository::class);
        $this->jobRunTracker = self::getContainer()->get(JobRunTracker::class);

        $this->em->createQuery('DELETE FROM App\Entity\JobRun')->execute();
    }

    public function testRecordsASuccessfulRun(): void
    {
        $now = new \DateTimeImmutable();
        $ran = false;

        $this->jobRunTracker->track('spool_drain', $now, function () use (&$ran) {
            $ran = true;
        });

        self::assertTrue($ran);
        $jobRun = $this->jobRunRepository->findOneBy(['jobName' => 'spool_drain']);
        self::assertNotNull($jobRun);
        self::assertSame('success', $jobRun->getStatus());
        self::assertEquals($now, $jobRun->getLastRunAt());
        self::assertNull($jobRun->getLastError());
    }

    public function testRecordsAFailureAndReThrows(): void
    {
        $now = new \DateTimeImmutable();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('storage backend unreachable');

        try {
            $this->jobRunTracker->track('tiering', $now, function () {
                throw new \RuntimeException('storage backend unreachable');
            });
        } finally {
            $jobRun = $this->jobRunRepository->findOneBy(['jobName' => 'tiering']);
            self::assertNotNull($jobRun);
            self::assertSame('error', $jobRun->getStatus());
            self::assertSame('storage backend unreachable', $jobRun->getLastError());
        }
    }

    public function testASubsequentRunOverwritesThePreviousOutcome(): void
    {
        try {
            $this->jobRunTracker->track('log_entry_prune', new \DateTimeImmutable('-1 hour'), function () {
                throw new \RuntimeException('first attempt failed');
            });
        } catch (\RuntimeException) {
            // Expected — the point of this test is what happens on the next run.
        }

        $secondRunAt = new \DateTimeImmutable();
        $this->jobRunTracker->track('log_entry_prune', $secondRunAt, function () {});

        $jobRun = $this->jobRunRepository->findOneBy(['jobName' => 'log_entry_prune']);
        self::assertSame('success', $jobRun->getStatus());
        self::assertNull($jobRun->getLastError());
        self::assertEquals($secondRunAt, $jobRun->getLastRunAt());
    }
}
