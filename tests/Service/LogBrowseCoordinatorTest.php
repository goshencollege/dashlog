<?php

namespace App\Tests\Service;

use App\Entity\LogEntry;
use App\Entity\LogObject;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Repository\LogEntryRepository;
use App\Repository\LogObjectRepository;
use App\Service\LogArchiveSearchService;
use App\Service\LogBrowseCoordinator;
use App\Service\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LogBrowseCoordinatorTest extends KernelTestCase
{
    private string $backendDir;
    private EntityManagerInterface $em;
    private StorageService $storageService;
    private LogEntryRepository $logEntryRepository;
    private LogObjectRepository $logObjectRepository;
    private StorageBackend $backend;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->storageService = self::getContainer()->get(StorageService::class);
        $this->logEntryRepository = self::getContainer()->get(LogEntryRepository::class);
        $this->logObjectRepository = self::getContainer()->get(LogObjectRepository::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();

        $this->backendDir = sys_get_temp_dir() . '/dashlog-coordinator-' . bin2hex(random_bytes(4));
        mkdir($this->backendDir);

        $this->backend = new StorageBackend();
        $this->backend->setName('Test Backend');
        $this->backend->setType(StorageBackendType::Local);
        $this->backend->setPath($this->backendDir);
        $this->em->persist($this->backend);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->backendDir);
        parent::tearDown();
    }

    public function testUsesFastDbOnlyPathWhenNothingOverlappingIsUncached(): void
    {
        $object = $this->makeCachedObject('2026-08-11T00:00:00+00:00');
        $this->makeEntry($object, 'recent', new \DateTimeImmutable('2026-08-11T00:05:00+00:00'));

        $result = $this->coordinator()->search([], 1, 50);

        self::assertFalse($result['usingArchive']);
        self::assertSame(1, $result['total']);
        self::assertSame('recent', $result['results'][0]->getMessage());
    }

    public function testMergesDbAndArchiveResultsWhenSomeOverlappingObjectsAreUncached(): void
    {
        $cachedObject = $this->makeCachedObject('2026-08-11T00:00:00+00:00');
        $this->makeEntry($cachedObject, 'recent still in db', new \DateTimeImmutable('2026-08-11T00:05:00+00:00'));

        $uncachedObject = $this->writeArchivedObject('web-01/2026/07/01/00-00.log.gz', [
            $this->line('2026-07-01T00:01:00+00:00', 'old, only in storage'),
        ]);

        $result = $this->coordinator()->search([], 1, 50);

        self::assertTrue($result['usingArchive']);
        self::assertSame(2, $result['total']);
        $messages = array_map(static fn (LogEntry $e) => $e->getMessage(), $result['results']);
        self::assertSame(['recent still in db', 'old, only in storage'], $messages);
    }

    public function testPaginatesMergedResultsInMemory(): void
    {
        $cachedObject = $this->makeCachedObject('2026-08-11T00:00:00+00:00');
        $this->makeEntry($cachedObject, 'newest', new \DateTimeImmutable('2026-08-11T00:05:00+00:00'));

        $this->writeArchivedObject('web-01/2026/07/01/00-00.log.gz', [
            $this->line('2026-07-01T00:01:00+00:00', 'oldest'),
        ]);

        $page1 = $this->coordinator()->search([], 1, 1);
        $page2 = $this->coordinator()->search([], 2, 1);

        self::assertSame(2, $page1['total']);
        self::assertSame('newest', $page1['results'][0]->getMessage());
        self::assertSame('oldest', $page2['results'][0]->getMessage());
    }

    public function testLogsWarningWhenDbMergeCapIsHit(): void
    {
        $cachedObject = $this->makeCachedObject('2026-08-11T00:00:00+00:00');
        $this->makeEntry($cachedObject, 'a', new \DateTimeImmutable('2026-08-11T00:01:00+00:00'));
        $this->makeEntry($cachedObject, 'b', new \DateTimeImmutable('2026-08-11T00:02:00+00:00'));

        $this->writeArchivedObject('web-01/2026/07/01/00-00.log.gz', [
            $this->line('2026-07-01T00:01:00+00:00', 'old'),
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        // A cap of 1 forces the capped branch even though only 2 rows exist in the DB.
        $coordinator = new LogBrowseCoordinator(
            $this->logEntryRepository,
            $this->logObjectRepository,
            new LogArchiveSearchService($this->storageService, new NullLogger()),
            $logger,
            maxMergeDbResults: 1,
        );

        $result = $coordinator->search([], 1, 50);
        self::assertTrue($result['usingArchive']);
    }

    private function coordinator(): LogBrowseCoordinator
    {
        return new LogBrowseCoordinator(
            $this->logEntryRepository,
            $this->logObjectRepository,
            new LogArchiveSearchService($this->storageService, new NullLogger()),
            new NullLogger(),
        );
    }

    private function makeCachedObject(string $windowStartIso): LogObject
    {
        $windowStart = new \DateTimeImmutable($windowStartIso);
        $object = new LogObject();
        $object->setStorageBackend($this->backend);
        $object->setObjectKey('web-01/' . $windowStart->format('Y/m/d/H-i') . '-cached.log.gz');
        $object->setSource('web-01');
        $object->setWindowStart($windowStart);
        $object->setWindowEnd($windowStart->modify('+15 minutes'));
        $object->setSizeBytes(1);
        $object->setEntryCount(1);
        $object->setStatus('stored');
        $this->em->persist($object);
        $this->em->flush();

        return $object;
    }

    private function makeEntry(LogObject $object, string $message, \DateTimeImmutable $timestamp): LogEntry
    {
        $entry = new LogEntry();
        $entry->setLogObject($object);
        $entry->setSource($object->getSource());
        $entry->setTimestamp($timestamp);
        $entry->setSeverity(5);
        $entry->setMessage($message);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /** @param array<string, mixed>[] $lines */
    private function writeArchivedObject(string $key, array $lines): LogObject
    {
        $content = '';
        foreach ($lines as $line) {
            $content .= json_encode($line, JSON_THROW_ON_ERROR) . "\n";
        }
        $gzipped = gzencode($content, 9);
        $this->storageService->write($this->backend, $key, $gzipped);

        $object = new LogObject();
        $object->setStorageBackend($this->backend);
        $object->setObjectKey($key);
        $object->setSource($lines[0]['source']);
        $object->setWindowStart(new \DateTimeImmutable($lines[0]['timestamp']));
        $object->setWindowEnd(new \DateTimeImmutable($lines[count($lines) - 1]['timestamp']));
        $object->setSizeBytes(strlen($gzipped));
        $object->setChecksumSha256(hash('sha256', $gzipped));
        $object->setEntryCount(count($lines));
        $object->setStatus('stored');
        $object->setEntriesCached(false);
        $this->em->persist($object);
        $this->em->flush();

        return $object;
    }

    /** @return array<string, mixed> */
    private function line(string $timestamp, string $message): array
    {
        return [
            'source' => 'web-01',
            'timestamp' => $timestamp,
            'host' => null,
            'appName' => null,
            'procId' => null,
            'severity' => 5,
            'facility' => 4,
            'message' => $message,
            'raw' => $message,
        ];
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
