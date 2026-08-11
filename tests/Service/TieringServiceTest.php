<?php

namespace App\Tests\Service;

use App\Entity\LogObject;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Service\StorageService;
use App\Service\TieringService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TieringServiceTest extends KernelTestCase
{
    private string $hotDir;
    private string $warmDir;
    private string $coldDir;
    private EntityManagerInterface $em;
    private StorageService $storageService;
    private TieringService $tieringService;
    private StorageBackend $hot;
    private StorageBackend $warm;
    private StorageBackend $cold;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->storageService = self::getContainer()->get(StorageService::class);
        $this->tieringService = self::getContainer()->get(TieringService::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();

        $this->hotDir = sys_get_temp_dir() . '/dashlog-tier-hot-' . bin2hex(random_bytes(4));
        $this->warmDir = sys_get_temp_dir() . '/dashlog-tier-warm-' . bin2hex(random_bytes(4));
        $this->coldDir = sys_get_temp_dir() . '/dashlog-tier-cold-' . bin2hex(random_bytes(4));
        mkdir($this->hotDir);
        mkdir($this->warmDir);
        mkdir($this->coldDir);

        // hot (rank 0, max 1 day) -> warm (rank 1, max 7 days) -> cold (rank 2, no max)
        $this->hot = $this->makeBackend('Hot', $this->hotDir, tierRank: 0, maxAgeDays: 1);
        $this->warm = $this->makeBackend('Warm', $this->warmDir, tierRank: 1, maxAgeDays: 7);
        $this->cold = $this->makeBackend('Cold', $this->coldDir, tierRank: 2, maxAgeDays: null);
    }

    protected function tearDown(): void
    {
        foreach ([$this->hotDir, $this->warmDir, $this->coldDir] as $dir) {
            $this->removeDirectory($dir);
        }
        parent::tearDown();
    }

    public function testOldObjectOnHotBackendMigratesToWarm(): void
    {
        $now = new \DateTimeImmutable('2026-08-11T00:00:00+00:00');
        $logObject = $this->writeObject($this->hot, 'web-01/2026/08/09/00-00.log.gz', 'old', $now->modify('-3 days'));

        $this->tieringService->run($now);

        self::assertSame($this->warm->getId(), $logObject->getStorageBackend()->getId());
        self::assertSame(1, $logObject->getTierRank());
    }

    public function testRecentObjectOnHotBackendStaysPut(): void
    {
        $now = new \DateTimeImmutable('2026-08-11T00:00:00+00:00');
        $logObject = $this->writeObject($this->hot, 'web-01/2026/08/10/23-45.log.gz', 'recent', $now->modify('-1 hour'));

        $this->tieringService->run($now);

        self::assertSame($this->hot->getId(), $logObject->getStorageBackend()->getId());
    }

    public function testColdestBackendIsNeverTieredEvenIfOld(): void
    {
        $now = new \DateTimeImmutable('2026-08-11T00:00:00+00:00');
        $logObject = $this->writeObject($this->cold, 'web-01/2026/01/01/00-00.log.gz', 'ancient', $now->modify('-200 days'));

        $this->tieringService->run($now);

        self::assertSame($this->cold->getId(), $logObject->getStorageBackend()->getId());
    }

    private function writeObject(StorageBackend $backend, string $key, string $content, \DateTimeImmutable $windowEnd): LogObject
    {
        $this->storageService->write($backend, $key, $content);
        $this->storageService->write($backend, str_replace('.log.gz', '.meta.json', $key), json_encode(['format' => 'ndjson.gz']));

        $logObject = new LogObject();
        $logObject->setStorageBackend($backend);
        $logObject->setObjectKey($key);
        $logObject->setSource('web-01');
        $logObject->setTierRank($backend->getTierRank());
        $logObject->setWindowStart($windowEnd->modify('-15 minutes'));
        $logObject->setWindowEnd($windowEnd);
        $logObject->setSizeBytes(strlen($content));
        $logObject->setChecksumSha256(hash('sha256', $content));
        $logObject->setEntryCount(1);
        $logObject->setStatus('stored');
        $this->em->persist($logObject);
        $this->em->flush();

        return $logObject;
    }

    private function makeBackend(string $name, string $path, int $tierRank, ?int $maxAgeDays): StorageBackend
    {
        $backend = new StorageBackend();
        $backend->setName($name);
        $backend->setType(StorageBackendType::Local);
        $backend->setPath($path);
        $backend->setIsActive(true);
        $backend->setTierRank($tierRank);
        $backend->setMaxAgeDays($maxAgeDays);
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
