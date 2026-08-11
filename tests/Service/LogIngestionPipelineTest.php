<?php

namespace App\Tests\Service;

use App\Dto\IngestedLogLine;
use App\Entity\LogEntry;
use App\Entity\LogObject;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Service\LogIngestor;
use App\Service\SpoolProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LogIngestionPipelineTest extends KernelTestCase
{
    private const SPOOL_PATH = '/var/www/html/var/log-spool-test';

    private string $tmpDir;
    private EntityManagerInterface $em;
    private LogIngestor $ingestor;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ingestor = self::getContainer()->get(LogIngestor::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();
        $this->removeDirectory(self::SPOOL_PATH);

        $this->tmpDir = sys_get_temp_dir() . '/dashlog-ingest-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);

        $backend = new StorageBackend();
        $backend->setName('Test Local');
        $backend->setType(StorageBackendType::Local);
        $backend->setPath($this->tmpDir);
        $backend->setIsActive(true);
        $this->em->persist($backend);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        $this->removeDirectory(self::SPOOL_PATH);
        parent::tearDown();
    }

    public function testFlushAllWritesBatchToSpoolAndCatalogsIt(): void
    {
        $windowStart = new \DateTimeImmutable('2026-08-11T14:15:00+00:00');

        $this->ingestor->ingest(new IngestedLogLine(
            source: 'web-01',
            timestamp: $windowStart->modify('+1 minute'),
            host: 'web-01',
            appName: 'sshd',
            procId: '1234',
            severity: 5,
            facility: 4,
            message: 'first message',
            raw: '<...>first message',
        ));
        $this->ingestor->ingest(new IngestedLogLine(
            source: 'web-01',
            timestamp: $windowStart->modify('+2 minutes'),
            host: 'web-01',
            appName: 'sshd',
            procId: '1234',
            severity: 6,
            facility: 4,
            message: 'second message',
            raw: '<...>second message',
        ));

        $this->ingestor->flushAll(new \DateTimeImmutable('2026-08-11T14:16:00+00:00'));

        $logObject = $this->em->getRepository(LogObject::class)->findOneBy(['source' => 'web-01']);
        self::assertNotNull($logObject);
        // Ingestion always lands on the write-ahead spool first, never
        // directly on a "real" backend — draining is a separate step.
        self::assertTrue($logObject->getStorageBackend()->isSpool());
        self::assertSame('staged', $logObject->getStatus());
        self::assertSame(2, $logObject->getEntryCount());
        self::assertSame('web-01/2026/08/11/14-15.log.gz', $logObject->getObjectKey());

        $entries = $this->em->getRepository(LogEntry::class)->findBy(['source' => 'web-01'], ['timestamp' => 'ASC']);
        self::assertCount(2, $entries);
        self::assertSame('first message', $entries[0]->getMessage());
        self::assertSame('second message', $entries[1]->getMessage());
        self::assertSame($logObject->getId(), $entries[0]->getLogObject()->getId());

        $storedPath = self::SPOOL_PATH . '/web-01/2026/08/11/14-15.log.gz';
        self::assertFileExists($storedPath);

        $lines = array_values(array_filter(explode("\n", gzdecode(file_get_contents($storedPath)))));
        self::assertCount(2, $lines);
        $decoded = json_decode($lines[0], true);
        self::assertSame('first message', $decoded['message']);

        $metaPath = self::SPOOL_PATH . '/web-01/2026/08/11/14-15.meta.json';
        self::assertFileExists($metaPath);
        $meta = json_decode(file_get_contents($metaPath), true);
        self::assertSame(2, $meta['entryCount']);
        self::assertSame($logObject->getChecksumSha256(), $meta['checksumSha256']);
    }

    public function testIngestionSucceedsEvenWithNoActiveRealBackend(): void
    {
        $this->em->createQuery('UPDATE App\Entity\StorageBackend s SET s.isActive = false')->execute();

        $windowStart = new \DateTimeImmutable('2026-08-11T14:15:00+00:00');
        $this->ingestor->ingest(new IngestedLogLine(
            source: 'web-02',
            timestamp: $windowStart,
            host: 'web-02',
            appName: 'sshd',
            procId: null,
            severity: 5,
            facility: 4,
            message: 'still arrives',
            raw: 'still arrives',
        ));

        // The spool is always available regardless of real backend health,
        // so this must succeed — only later draining would need a real
        // active backend.
        $this->ingestor->flushAll(new \DateTimeImmutable('2026-08-11T14:16:00+00:00'));

        $logObject = $this->em->getRepository(LogObject::class)->findOneBy(['source' => 'web-02']);
        self::assertNotNull($logObject);
        self::assertTrue($logObject->getStorageBackend()->isSpool());
        self::assertSame('staged', $logObject->getStatus());
    }

    public function testFlushRecoversAfterEntityManagerIsClosedByAPriorFailure(): void
    {
        // Doctrine permanently closes an EntityManager after any failed
        // flush (a deadlock, a dropped connection, anything) — every later
        // call on that same instance keeps throwing after that. Since
        // SyslogListenCommand's loop reuses the same injected services for
        // the lifetime of the process, this must self-heal rather than wedge
        // ingestion forever. Simulate the "already closed" precondition
        // directly rather than trying to trigger a real flush failure.
        $this->em->close();

        $windowStart = new \DateTimeImmutable('2026-08-11T14:15:00+00:00');
        $this->ingestor->ingest(new IngestedLogLine(
            source: 'web-03',
            timestamp: $windowStart,
            host: 'web-03',
            appName: 'sshd',
            procId: null,
            severity: 5,
            facility: 4,
            message: 'survives a closed entity manager',
            raw: 'survives a closed entity manager',
        ));

        $this->ingestor->flushAll(new \DateTimeImmutable('2026-08-11T14:16:00+00:00'));

        // The closed EM from setUp is now stale; fetch a fresh one to verify.
        $em = self::getContainer()->get(ManagerRegistry::class)->resetManager();
        $logObject = $em->getRepository(LogObject::class)->findOneBy(['source' => 'web-03']);
        self::assertNotNull($logObject);
        self::assertSame('staged', $logObject->getStatus());
    }

    public function testSecondBurstLandingInAnAlreadyFlushedWindowMergesInsteadOfColliding(): void
    {
        // A source's traffic can split across a gap that straddles a window
        // boundary: the window closes and flushes, then more lines for that
        // *same* window arrive afterward. The second flush must not silently
        // clobber the first's stored object while failing to update its
        // catalog row (object_key is unique per backend) — that would leave
        // the recorded checksum permanently wrong. This is exactly what
        // happened with a real "minnesota" source once batching windows
        // were shortened to 1 minute for faster local testing.
        $windowStart = new \DateTimeImmutable('2026-08-11T14:15:00+00:00');

        $this->ingestor->ingest(new IngestedLogLine(
            source: 'minnesota', timestamp: $windowStart, host: 'minnesota', appName: 'Hostd',
            procId: null, severity: 5, facility: 4, message: 'first burst', raw: 'first burst',
        ));
        $this->ingestor->flushAll(new \DateTimeImmutable('2026-08-11T14:16:00+00:00'));

        $this->ingestor->ingest(new IngestedLogLine(
            source: 'minnesota', timestamp: $windowStart->modify('+30 seconds'), host: 'minnesota', appName: 'Hostd',
            procId: null, severity: 5, facility: 4, message: 'second burst', raw: 'second burst',
        ));
        $this->ingestor->flushAll(new \DateTimeImmutable('2026-08-11T14:17:00+00:00'));

        $objects = $this->em->getRepository(LogObject::class)->findBy(['source' => 'minnesota']);
        self::assertCount(1, $objects, 'the second flush must merge into the same catalog row, not create a second one');

        $logObject = $objects[0];
        self::assertSame(2, $logObject->getEntryCount());

        $entries = $this->em->getRepository(LogEntry::class)->findBy(['source' => 'minnesota'], ['timestamp' => 'ASC']);
        self::assertCount(2, $entries);
        self::assertSame('first burst', $entries[0]->getMessage());
        self::assertSame('second burst', $entries[1]->getMessage());

        $storedPath = self::SPOOL_PATH . '/minnesota/2026/08/11/14-15.log.gz';
        $lines = array_values(array_filter(explode("\n", gzdecode(file_get_contents($storedPath)))));
        self::assertCount(2, $lines, 'the stored object must contain both bursts, not just the second');

        // The catalog's recorded checksum must match what is actually
        // stored — this is the exact invariant that broke in production.
        self::assertSame(hash('sha256', file_get_contents($storedPath)), $logObject->getChecksumSha256());
    }

    public function testLinesAreVisibleBeforeTheirWindowCloses(): void
    {
        $windowStart = new \DateTimeImmutable('2026-08-11T14:15:00+00:00');
        $this->ingestor->ingest(new IngestedLogLine(
            source: 'web-04', timestamp: $windowStart, host: 'web-04', appName: 'sshd',
            procId: null, severity: 5, facility: 4, message: 'visible early', raw: 'visible early',
        ));

        // No flushExpiredWindows/flushAll call — the window is still open.
        $this->ingestor->flushForVisibility();

        $logObject = $this->em->getRepository(LogObject::class)->findOneBy(['source' => 'web-04']);
        self::assertNotNull($logObject, 'a pending catalog row should exist immediately');
        self::assertSame('pending', $logObject->getStatus());
        self::assertSame(1, $logObject->getEntryCount());
        self::assertNull($logObject->getChecksumSha256(), 'no bytes have been written yet');

        $entries = $this->em->getRepository(LogEntry::class)->findBy(['source' => 'web-04']);
        self::assertCount(1, $entries, 'the entry must be queryable/browsable immediately');
        self::assertSame('visible early', $entries[0]->getMessage());

        self::assertFileDoesNotExist(self::SPOOL_PATH . '/web-04/2026/08/11/14-15.log.gz');
    }

    public function testWindowCloseFinalizesAPreviouslyVisibleObject(): void
    {
        $windowStart = new \DateTimeImmutable('2026-08-11T14:15:00+00:00');
        $this->ingestor->ingest(new IngestedLogLine(
            source: 'web-05', timestamp: $windowStart, host: 'web-05', appName: 'sshd',
            procId: null, severity: 5, facility: 4, message: 'made visible early', raw: 'made visible early',
        ));
        $this->ingestor->flushForVisibility();

        $this->ingestor->ingest(new IngestedLogLine(
            source: 'web-05', timestamp: $windowStart->modify('+1 minute'), host: 'web-05', appName: 'sshd',
            procId: null, severity: 5, facility: 4, message: 'arrived just before close', raw: 'arrived just before close',
        ));
        // Window closes with one line already recorded via flushForVisibility
        // and one line only in pendingEntryLines — flushExpiredWindows must
        // drain the remainder before finalizing.
        $this->ingestor->flushAll(new \DateTimeImmutable('2026-08-11T14:17:00+00:00'));

        $logObject = $this->em->getRepository(LogObject::class)->findOneBy(['source' => 'web-05']);
        self::assertSame('staged', $logObject->getStatus());
        self::assertSame(2, $logObject->getEntryCount());
        self::assertNotNull($logObject->getChecksumSha256());

        $entries = $this->em->getRepository(LogEntry::class)->findBy(['source' => 'web-05'], ['timestamp' => 'ASC']);
        self::assertCount(2, $entries, 'no duplicate LogEntry rows from being recorded twice');
        self::assertSame('made visible early', $entries[0]->getMessage());
        self::assertSame('arrived just before close', $entries[1]->getMessage());

        $storedPath = self::SPOOL_PATH . '/web-05/2026/08/11/14-15.log.gz';
        self::assertFileExists($storedPath);
        $lines = array_values(array_filter(explode("\n", gzdecode(file_get_contents($storedPath)))));
        self::assertCount(2, $lines, 'both lines must be in the stored object, not just the one recorded at finalize time');
    }

    public function testSpoolIsCreatedOnceAndReused(): void
    {
        $windowStart = new \DateTimeImmutable('2026-08-11T14:15:00+00:00');
        $this->ingestor->ingest(new IngestedLogLine(
            source: 'web-01', timestamp: $windowStart, host: 'web-01', appName: 'sshd',
            procId: null, severity: 5, facility: 4, message: 'one', raw: 'one',
        ));
        $this->ingestor->flushAll(new \DateTimeImmutable('2026-08-11T14:16:00+00:00'));

        $spoolProvider = self::getContainer()->get(SpoolProvider::class);
        $spool = $spoolProvider->getSpool();

        self::assertCount(1, $this->em->getRepository(StorageBackend::class)->findBy(['isSpool' => true]));
        self::assertSame(self::SPOOL_PATH, $spool->getPath());
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
