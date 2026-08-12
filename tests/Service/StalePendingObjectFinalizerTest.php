<?php

namespace App\Tests\Service;

use App\Entity\LogEntry;
use App\Entity\LogObject;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Service\StalePendingObjectFinalizer;
use App\Service\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class StalePendingObjectFinalizerTest extends KernelTestCase
{
    private string $tmpDir;
    private EntityManagerInterface $em;
    private StorageService $storageService;
    private StalePendingObjectFinalizer $finalizer;
    private StorageBackend $backend;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->storageService = self::getContainer()->get(StorageService::class);
        $this->finalizer = self::getContainer()->get(StalePendingObjectFinalizer::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();

        $this->tmpDir = sys_get_temp_dir() . '/dashlog-stale-pending-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);

        $this->backend = new StorageBackend();
        $this->backend->setName('Spool');
        $this->backend->setType(StorageBackendType::Local);
        $this->backend->setPath($this->tmpDir);
        $this->backend->setIsSpool(true);
        $this->em->persist($this->backend);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testFinalizesAPendingObjectWhoseWindowClosedLongAgo(): void
    {
        $now = new \DateTimeImmutable('2026-08-12T15:00:00+00:00');
        $windowStart = $now->modify('-3 hours');
        $windowEnd = $windowStart->modify('+15 minutes');

        $logObject = $this->makePendingObject('web-01', $windowStart, $windowEnd);
        $this->makeEntry($logObject, 'first line', $windowStart->modify('+1 minute'));
        $this->makeEntry($logObject, 'second line', $windowStart->modify('+2 minutes'));

        $finalized = $this->finalizer->run($now);

        self::assertSame(1, $finalized);
        self::assertSame('staged', $logObject->getStatus());
        self::assertSame(2, $logObject->getEntryCount());
        self::assertNotNull($logObject->getChecksumSha256());

        $content = gzdecode($this->storageService->read($this->backend, $logObject->getObjectKey()));
        $lines = array_values(array_filter(explode("\n", $content)));
        self::assertCount(2, $lines);
        self::assertSame('first line', json_decode($lines[0], true)['message']);
        self::assertSame('second line', json_decode($lines[1], true)['message']);
    }

    public function testLeavesARecentlyOpenedPendingObjectAlone(): void
    {
        $now = new \DateTimeImmutable('2026-08-12T15:00:00+00:00');
        $windowStart = $now->modify('-5 minutes');
        $windowEnd = $windowStart->modify('+15 minutes');

        $logObject = $this->makePendingObject('web-02', $windowStart, $windowEnd);
        $this->makeEntry($logObject, 'still arriving', $windowStart);

        $finalized = $this->finalizer->run($now);

        self::assertSame(0, $finalized);
        self::assertSame('pending', $logObject->getStatus());
    }

    public function testSkipsAStalePendingObjectWithNoEntriesWithoutThrowing(): void
    {
        $now = new \DateTimeImmutable('2026-08-12T15:00:00+00:00');
        $windowStart = $now->modify('-3 hours');
        $windowEnd = $windowStart->modify('+15 minutes');

        $logObject = $this->makePendingObject('web-03', $windowStart, $windowEnd);
        // Deliberately no LogEntry rows — shouldn't happen in practice, but
        // must not crash the sweep if it somehow does.

        $finalized = $this->finalizer->run($now);

        self::assertSame(0, $finalized);
        self::assertSame('pending', $logObject->getStatus());
    }

    public function testOneFailingObjectDoesNotStopTheRestOfTheSweep(): void
    {
        $now = new \DateTimeImmutable('2026-08-12T15:00:00+00:00');
        $windowStart = $now->modify('-3 hours');
        $windowEnd = $windowStart->modify('+15 minutes');

        $empty = $this->makePendingObject('web-04', $windowStart, $windowEnd);
        // No entries for $empty — skipped, not fatal.

        $good = $this->makePendingObject('web-05', $windowStart, $windowEnd);
        $this->makeEntry($good, 'made it through', $windowStart);

        $finalized = $this->finalizer->run($now);

        self::assertSame(1, $finalized);
        self::assertSame('pending', $empty->getStatus());
        self::assertSame('staged', $good->getStatus());
    }

    private function makePendingObject(string $source, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): LogObject
    {
        $logObject = new LogObject();
        $logObject->setStorageBackend($this->backend);
        $logObject->setObjectKey($source . '/' . $windowStart->format('Y/m/d/H-i') . '.log.gz');
        $logObject->setSource($source);
        $logObject->setTierRank($this->backend->getTierRank());
        $logObject->setWindowStart($windowStart);
        $logObject->setWindowEnd($windowEnd);
        $logObject->setSizeBytes(0);
        $logObject->setEntryCount(0);
        $logObject->setStatus('pending');
        $this->em->persist($logObject);
        $this->em->flush();

        return $logObject;
    }

    private function makeEntry(LogObject $logObject, string $message, \DateTimeImmutable $timestamp): LogEntry
    {
        $entry = new LogEntry();
        $entry->setLogObject($logObject);
        $entry->setSource($logObject->getSource());
        $entry->setTimestamp($timestamp);
        $entry->setSeverity(5);
        $entry->setMessage($message);
        $this->em->persist($entry);
        $logObject->setEntryCount($logObject->getEntryCount() + 1);
        $this->em->persist($logObject);
        $this->em->flush();

        return $entry;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
