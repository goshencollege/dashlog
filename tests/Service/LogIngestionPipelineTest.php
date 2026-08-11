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
