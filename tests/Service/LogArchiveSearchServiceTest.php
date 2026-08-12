<?php

namespace App\Tests\Service;

use App\Entity\LogObject;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Service\LogArchiveSearchService;
use App\Service\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LogArchiveSearchServiceTest extends KernelTestCase
{
    private string $backendDir;
    private EntityManagerInterface $em;
    private StorageService $storageService;
    private StorageBackend $backend;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->storageService = self::getContainer()->get(StorageService::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();

        $this->backendDir = sys_get_temp_dir() . '/dashlog-archive-' . bin2hex(random_bytes(4));
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

    public function testReconstructsEntriesFromStoredObject(): void
    {
        $object = $this->writeObject('web-01/2026/07/01/00-00.log.gz', [
            $this->line('web-01', '2026-07-01T00:01:00+00:00', severity: 3, message: 'error line'),
            $this->line('web-01', '2026-07-01T00:02:00+00:00', severity: 6, message: 'info line'),
        ]);

        $service = new LogArchiveSearchService($this->storageService, $this->createLogger());
        $entries = $service->searchObjects([$object], []);

        self::assertCount(2, $entries);
        $messages = array_map(static fn ($e) => $e->getMessage(), $entries);
        self::assertContains('error line', $messages);
        self::assertContains('info line', $messages);
    }

    public function testAppliesSeverityMessageAndTimeRangeFiltersInMemory(): void
    {
        $object = $this->writeObject('web-01/2026/07/01/00-00.log.gz', [
            $this->line('web-01', '2026-07-01T00:01:00+00:00', severity: 3, message: 'connection refused'),
            $this->line('web-01', '2026-07-01T00:02:00+00:00', severity: 6, message: 'all good'),
            $this->line('web-01', '2026-07-01T00:03:00+00:00', severity: 3, message: 'too late', ),
        ]);

        $service = new LogArchiveSearchService($this->storageService, $this->createLogger());
        $entries = $service->searchObjects([$object], [
            'severity' => [3],
            'message' => 'refused',
            'to' => new \DateTimeImmutable('2026-07-01T00:01:30+00:00'),
        ]);

        self::assertCount(1, $entries);
        self::assertSame('connection refused', $entries[0]->getMessage());
    }

    public function testSkipsAnObjectWhoseKeyWasNeverWrittenWithoutFailingTheRest(): void
    {
        $missing = new LogObject();
        $missing->setStorageBackend($this->backend);
        $missing->setObjectKey('web-02/2026/07/01/00-00.log.gz');
        $missing->setSource('web-02');
        $missing->setWindowStart(new \DateTimeImmutable('2026-07-01T00:00:00+00:00'));
        $missing->setWindowEnd(new \DateTimeImmutable('2026-07-01T00:15:00+00:00'));
        $missing->setSizeBytes(1);
        $missing->setEntryCount(1);
        $missing->setStatus('stored');
        $this->em->persist($missing);
        $this->em->flush();

        $present = $this->writeObject('web-01/2026/07/01/00-00.log.gz', [
            $this->line('web-01', '2026-07-01T00:01:00+00:00', severity: 3, message: 'still readable'),
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = new LogArchiveSearchService($this->storageService, $logger);
        $entries = $service->searchObjects([$missing, $present], []);

        self::assertCount(1, $entries);
        self::assertSame('still readable', $entries[0]->getMessage());
    }

    /** @param array<string, mixed>[] $lines */
    private function writeObject(string $key, array $lines): LogObject
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
        $this->em->persist($object);
        $this->em->flush();

        return $object;
    }

    /** @return array<string, mixed> */
    private function line(string $source, string $timestamp, ?int $severity, string $message): array
    {
        return [
            'source' => $source,
            'timestamp' => $timestamp,
            'host' => null,
            'appName' => null,
            'procId' => null,
            'severity' => $severity,
            'facility' => 4,
            'message' => $message,
            'raw' => $message,
        ];
    }

    private function createLogger(): LoggerInterface
    {
        return new NullLogger();
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
