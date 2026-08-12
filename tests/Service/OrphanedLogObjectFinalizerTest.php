<?php

namespace App\Tests\Service;

use App\Entity\LogEntry;
use App\Entity\LogObject;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Service\OrphanedLogObjectFinalizer;
use App\Service\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class OrphanedLogObjectFinalizerTest extends KernelTestCase
{
    private string $tmpDir;
    private EntityManagerInterface $em;
    private StorageService $storageService;
    private OrphanedLogObjectFinalizer $finalizer;
    private StorageBackend $backend;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->storageService = self::getContainer()->get(StorageService::class);
        $this->finalizer = self::getContainer()->get(OrphanedLogObjectFinalizer::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();

        $this->tmpDir = sys_get_temp_dir() . '/dashlog-orphaned-' . bin2hex(random_bytes(4));
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

        $logObject = $this->makeObject('web-01', 'pending', $windowStart, $windowEnd);
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
    }

    public function testLeavesARecentlyOpenedPendingObjectAlone(): void
    {
        $now = new \DateTimeImmutable('2026-08-12T15:00:00+00:00');
        $windowStart = $now->modify('-5 minutes');
        $windowEnd = $windowStart->modify('+15 minutes');

        $logObject = $this->makeObject('web-02', 'pending', $windowStart, $windowEnd);
        $this->makeEntry($logObject, 'still arriving', $windowStart);

        $finalized = $this->finalizer->run($now);

        self::assertSame(0, $finalized);
        self::assertSame('pending', $logObject->getStatus());
    }

    public function testFinalizesAnErrorObjectWhoseContentIsActuallyUnreadable(): void
    {
        $now = new \DateTimeImmutable('2026-08-12T15:00:00+00:00');
        $windowStart = $now->modify('-10 minutes');
        $windowEnd = $windowStart->modify('+15 minutes');

        // 'error' status but no file was ever actually written for it —
        // simulates a source file that went missing outside the app.
        $logObject = $this->makeObject('web-03', 'error', $windowStart, $windowEnd);
        $logObject->setLastError('Unable to read file from location: web-03/... No such file or directory');
        $this->em->persist($logObject);
        $this->em->flush();
        $this->makeEntry($logObject, 'recovered line', $windowStart);

        $finalized = $this->finalizer->run($now);

        self::assertSame(1, $finalized);
        self::assertSame('staged', $logObject->getStatus());
        self::assertNull($logObject->getLastError(), 'a successful recovery must clear the stale error message');

        $content = gzdecode($this->storageService->read($this->backend, $logObject->getObjectKey()));
        self::assertStringContainsString('recovered line', $content);
    }

    public function testLeavesAnErrorObjectAloneWhenItsContentIsActuallyFine(): void
    {
        // status='error' can also mean an ordinary destination-write
        // failure where the object's own current file is completely
        // fine — that just needs the normal scheduled drain to retry it,
        // not a rebuild. Must not touch it.
        $now = new \DateTimeImmutable('2026-08-12T15:00:00+00:00');
        $windowStart = $now->modify('-10 minutes');
        $windowEnd = $windowStart->modify('+15 minutes');

        $logObject = $this->makeObject('web-04', 'error', $windowStart, $windowEnd);
        $logObject->setLastError('Some destination write failure, unrelated to this object\'s own file');
        $this->em->persist($logObject);
        $this->em->flush();
        $this->storageService->write($this->backend, $logObject->getObjectKey(), 'already fine');
        $this->makeEntry($logObject, 'irrelevant', $windowStart);

        $finalized = $this->finalizer->run($now);

        self::assertSame(0, $finalized);
        self::assertSame('error', $logObject->getStatus());
        self::assertNotNull($logObject->getLastError());
        self::assertSame('already fine', $this->storageService->read($this->backend, $logObject->getObjectKey()));
    }

    public function testSkipsAnOrphanWithNoEntriesWithoutThrowing(): void
    {
        $now = new \DateTimeImmutable('2026-08-12T15:00:00+00:00');
        $windowStart = $now->modify('-3 hours');
        $windowEnd = $windowStart->modify('+15 minutes');

        $logObject = $this->makeObject('web-05', 'pending', $windowStart, $windowEnd);
        // Deliberately no LogEntry rows.

        $finalized = $this->finalizer->run($now);

        self::assertSame(0, $finalized);
        self::assertSame('pending', $logObject->getStatus());
    }

    public function testOneFailingObjectDoesNotStopTheRestOfTheSweep(): void
    {
        $now = new \DateTimeImmutable('2026-08-12T15:00:00+00:00');
        $windowStart = $now->modify('-3 hours');
        $windowEnd = $windowStart->modify('+15 minutes');

        $empty = $this->makeObject('web-06', 'pending', $windowStart, $windowEnd);
        // No entries for $empty — skipped, not fatal.

        $good = $this->makeObject('web-07', 'pending', $windowStart, $windowEnd);
        $this->makeEntry($good, 'made it through', $windowStart);

        $finalized = $this->finalizer->run($now);

        self::assertSame(1, $finalized);
        self::assertSame('pending', $empty->getStatus());
        self::assertSame('staged', $good->getStatus());
    }

    private function makeObject(string $source, string $status, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): LogObject
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
        $logObject->setStatus($status);
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
