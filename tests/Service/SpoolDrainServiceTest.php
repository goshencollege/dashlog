<?php

namespace App\Tests\Service;

use App\Entity\LogObject;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Service\SpoolDrainService;
use App\Service\SpoolProvider;
use App\Service\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SpoolDrainServiceTest extends KernelTestCase
{
    private const SPOOL_PATH = '/var/www/html/var/log-spool-test';

    private string $realDir;
    private EntityManagerInterface $em;
    private StorageService $storageService;
    private SpoolProvider $spoolProvider;
    private SpoolDrainService $drainService;
    private StorageBackend $spool;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->storageService = self::getContainer()->get(StorageService::class);
        $this->spoolProvider = self::getContainer()->get(SpoolProvider::class);
        $this->drainService = self::getContainer()->get(SpoolDrainService::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();
        $this->removeDirectory(self::SPOOL_PATH);

        $this->realDir = sys_get_temp_dir() . '/dashlog-spool-drain-real-' . bin2hex(random_bytes(4));
        mkdir($this->realDir);

        $this->spool = $this->spoolProvider->getSpool();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->realDir);
        $this->removeDirectory(self::SPOOL_PATH);
        parent::tearDown();
    }

    public function testDrainsStagedObjectToRealActiveBackend(): void
    {
        $real = $this->makeRealBackend();
        $logObject = $this->writeToSpool('web-01/2026/08/11/14-15.log.gz', 'hello', status: 'staged');

        $this->drainService->run();

        self::assertSame($real->getId(), $logObject->getStorageBackend()->getId());
        self::assertSame('stored', $logObject->getStatus());
        self::assertTrue($this->storageService->exists($real, 'web-01/2026/08/11/14-15.log.gz'));
        self::assertFalse($this->storageService->exists($this->spool, 'web-01/2026/08/11/14-15.log.gz'));
    }

    public function testErrorStatusObjectOnSpoolIsAlsoDrained(): void
    {
        $real = $this->makeRealBackend();
        $logObject = $this->writeToSpool('web-01/2026/08/11/14-15.log.gz', 'hello', status: 'error');

        $this->drainService->run();

        self::assertSame($real->getId(), $logObject->getStorageBackend()->getId());
        self::assertSame('stored', $logObject->getStatus());
    }

    public function testLeavesObjectOnSpoolWhenNoActiveRealBackendExists(): void
    {
        $logObject = $this->writeToSpool('web-01/2026/08/11/14-15.log.gz', 'hello', status: 'staged');

        $this->drainService->run();

        self::assertTrue($logObject->getStorageBackend()->isSpool());
        self::assertSame('staged', $logObject->getStatus());
    }

    public function testRunWithNothingStagedIsANoOp(): void
    {
        $this->makeRealBackend();

        $this->drainService->run();

        self::assertCount(0, $this->em->getRepository(LogObject::class)->findAll());
    }

    public function testPendingObjectOnSpoolIsLeftAlone(): void
    {
        $this->makeRealBackend();

        $logObject = new LogObject();
        $logObject->setStorageBackend($this->spool);
        $logObject->setObjectKey('web-01/2026/08/11/14-15.log.gz');
        $logObject->setSource('web-01');
        $logObject->setWindowStart(new \DateTimeImmutable('2026-08-11T14:15:00+00:00'));
        $logObject->setWindowEnd(new \DateTimeImmutable('2026-08-11T14:15:00+00:00'));
        $logObject->setSizeBytes(0);
        $logObject->setEntryCount(1);
        $logObject->setStatus('pending');
        $this->em->persist($logObject);
        $this->em->flush();

        // Must not throw trying to read bytes that don't exist yet.
        $this->drainService->run();

        self::assertTrue($logObject->getStorageBackend()->isSpool());
        self::assertSame('pending', $logObject->getStatus());
    }

    private function writeToSpool(string $key, string $content, string $status): LogObject
    {
        $this->storageService->write($this->spool, $key, $content);
        $this->storageService->write($this->spool, str_replace('.log.gz', '.meta.json', $key), json_encode(['format' => 'ndjson.gz']));

        $logObject = new LogObject();
        $logObject->setStorageBackend($this->spool);
        $logObject->setObjectKey($key);
        $logObject->setSource('web-01');
        $logObject->setTierRank(0);
        $logObject->setWindowStart(new \DateTimeImmutable('2026-08-11T14:15:00+00:00'));
        $logObject->setWindowEnd(new \DateTimeImmutable('2026-08-11T14:30:00+00:00'));
        $logObject->setSizeBytes(strlen($content));
        $logObject->setChecksumSha256(hash('sha256', $content));
        $logObject->setEntryCount(1);
        $logObject->setStatus($status);
        $this->em->persist($logObject);
        $this->em->flush();

        return $logObject;
    }

    private function makeRealBackend(): StorageBackend
    {
        $backend = new StorageBackend();
        $backend->setName('Real Backend');
        $backend->setType(StorageBackendType::Local);
        $backend->setPath($this->realDir);
        $backend->setIsActive(true);
        $backend->setTierRank(0);
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
