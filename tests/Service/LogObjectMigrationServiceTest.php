<?php

namespace App\Tests\Service;

use App\Entity\LogObject;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Service\LogObjectMigrationService;
use App\Service\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LogObjectMigrationServiceTest extends KernelTestCase
{
    private string $sourceDir;
    private string $destDir;
    private EntityManagerInterface $em;
    private StorageService $storageService;
    private LogObjectMigrationService $migrationService;
    private StorageBackend $source;
    private StorageBackend $destination;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->storageService = self::getContainer()->get(StorageService::class);
        $this->migrationService = self::getContainer()->get(LogObjectMigrationService::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();

        $this->sourceDir = sys_get_temp_dir() . '/dashlog-migrate-src-' . bin2hex(random_bytes(4));
        $this->destDir = sys_get_temp_dir() . '/dashlog-migrate-dst-' . bin2hex(random_bytes(4));
        mkdir($this->sourceDir);
        mkdir($this->destDir);

        $this->source = $this->makeBackend('Source', $this->sourceDir, tierRank: 0);
        $this->destination = $this->makeBackend('Destination', $this->destDir, tierRank: 1);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->sourceDir);
        $this->removeDirectory($this->destDir);
        parent::tearDown();
    }

    public function testMigrateMovesObjectAndUpdatesCatalog(): void
    {
        $logObject = $this->writeObject($this->source, 'web-01/2026/08/11/14-15.log.gz', 'hello world');

        $this->migrationService->migrate($logObject, $this->destination);

        self::assertSame($this->destination->getId(), $logObject->getStorageBackend()->getId());
        self::assertSame(1, $logObject->getTierRank());
        self::assertSame('stored', $logObject->getStatus());
        self::assertNull($logObject->getLastError());

        self::assertFalse($this->storageService->exists($this->source, 'web-01/2026/08/11/14-15.log.gz'));
        self::assertFalse($this->storageService->exists($this->source, 'web-01/2026/08/11/14-15.meta.json'));
        self::assertTrue($this->storageService->exists($this->destination, 'web-01/2026/08/11/14-15.log.gz'));
        self::assertSame('hello world', $this->storageService->read($this->destination, 'web-01/2026/08/11/14-15.log.gz'));
    }

    public function testMigrateToSameBackendIsANoOp(): void
    {
        $logObject = $this->writeObject($this->source, 'web-01/2026/08/11/14-15.log.gz', 'hello world');

        $this->migrationService->migrate($logObject, $this->source);

        self::assertSame($this->source->getId(), $logObject->getStorageBackend()->getId());
        self::assertTrue($this->storageService->exists($this->source, 'web-01/2026/08/11/14-15.log.gz'));
    }

    public function testChecksumMismatchLeavesSourceIntactAndMarksError(): void
    {
        $logObject = $this->writeObject($this->source, 'web-01/2026/08/11/14-15.log.gz', 'hello world');
        // Simulate corruption/a bad recorded checksum.
        $logObject->setChecksumSha256('0000000000000000000000000000000000000000000000000000000000000000');
        $this->em->flush();

        $this->expectException(\RuntimeException::class);

        try {
            $this->migrationService->migrate($logObject, $this->destination);
        } finally {
            self::assertSame('error', $logObject->getStatus());
            self::assertNotNull($logObject->getLastError());
            self::assertTrue($this->storageService->exists($this->source, 'web-01/2026/08/11/14-15.log.gz'), 'source must not be deleted on a failed migration');
        }
    }

    private function writeObject(StorageBackend $backend, string $key, string $content): LogObject
    {
        $this->storageService->write($backend, $key, $content);
        $this->storageService->write($backend, str_replace('.log.gz', '.meta.json', $key), json_encode(['format' => 'ndjson.gz']));

        $logObject = new LogObject();
        $logObject->setStorageBackend($backend);
        $logObject->setObjectKey($key);
        $logObject->setSource('web-01');
        $logObject->setTierRank($backend->getTierRank());
        $logObject->setWindowStart(new \DateTimeImmutable('2026-08-11T14:15:00+00:00'));
        $logObject->setWindowEnd(new \DateTimeImmutable('2026-08-11T14:30:00+00:00'));
        $logObject->setSizeBytes(strlen($content));
        $logObject->setChecksumSha256(hash('sha256', $content));
        $logObject->setEntryCount(1);
        $logObject->setStatus('stored');
        $this->em->persist($logObject);
        $this->em->flush();

        return $logObject;
    }

    private function makeBackend(string $name, string $path, int $tierRank): StorageBackend
    {
        $backend = new StorageBackend();
        $backend->setName($name);
        $backend->setType(StorageBackendType::Local);
        $backend->setPath($path);
        $backend->setIsActive(true);
        $backend->setTierRank($tierRank);
        $this->em->persist($backend);
        $this->em->flush();

        return $backend;
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
