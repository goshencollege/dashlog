<?php

namespace App\Tests\Command;

use App\Entity\LogObject;
use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Service\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class LogCatalogReconcileCommandTest extends KernelTestCase
{
    private string $dir;
    private EntityManagerInterface $em;
    private StorageService $storageService;
    private StorageBackend $backend;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->storageService = self::getContainer()->get(StorageService::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();

        $this->dir = sys_get_temp_dir() . '/dashlog-reconcile-' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        $this->backend = new StorageBackend();
        $this->backend->setName('Reconcile Test');
        $this->backend->setType(StorageBackendType::Local);
        $this->backend->setPath($this->dir);
        $this->backend->setIsActive(true);
        $this->backend->setTierRank(2);
        $this->em->persist($this->backend);
        $this->em->flush();

        $application = new Application($kernel);
        $this->tester = new CommandTester($application->find('app:log-catalog:reconcile'));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
        parent::tearDown();
    }

    public function testRebuildsCatalogRowFromObjectAndSidecar(): void
    {
        $this->storageService->write($this->backend, 'web-01/2026/08/11/14-15.log.gz', 'hello');
        $this->storageService->write($this->backend, 'web-01/2026/08/11/14-15.meta.json', json_encode([
            'windowEnd' => '2026-08-11T14:30:00+00:00',
            'sizeBytes' => 5,
            'checksumSha256' => hash('sha256', 'hello'),
            'entryCount' => 3,
        ]));

        $exitCode = $this->tester->execute(['--backend' => (string) $this->backend->getId()]);

        self::assertSame(0, $exitCode);
        $logObject = $this->em->getRepository(LogObject::class)->findOneByBackendAndKey($this->backend, 'web-01/2026/08/11/14-15.log.gz');
        self::assertNotNull($logObject);
        self::assertSame('web-01', $logObject->getSource());
        self::assertSame(2, $logObject->getTierRank());
        self::assertEquals(new \DateTimeImmutable('2026-08-11T14:15:00+00:00'), $logObject->getWindowStart());
        self::assertEquals(new \DateTimeImmutable('2026-08-11T14:30:00+00:00'), $logObject->getWindowEnd());
        self::assertSame(5, $logObject->getSizeBytes());
        self::assertSame(hash('sha256', 'hello'), $logObject->getChecksumSha256());
        self::assertSame(3, $logObject->getEntryCount());
        self::assertSame('stored', $logObject->getStatus());
    }

    public function testFallsBackToListingMetadataWhenSidecarMissing(): void
    {
        $this->storageService->write($this->backend, 'web-01/2026/08/11/14-15.log.gz', 'hello');

        $exitCode = $this->tester->execute(['--backend' => (string) $this->backend->getId()]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Missing/unreadable meta.json (best-effort fallback used): 1', $this->tester->getDisplay());

        $logObject = $this->em->getRepository(LogObject::class)->findOneByBackendAndKey($this->backend, 'web-01/2026/08/11/14-15.log.gz');
        self::assertNotNull($logObject);
        self::assertSame(5, $logObject->getSizeBytes());
        self::assertNull($logObject->getChecksumSha256());
        self::assertNull($logObject->getEntryCount());
        self::assertEquals(new \DateTimeImmutable('2026-08-11T14:30:00+00:00'), $logObject->getWindowEnd());
    }

    public function testAlreadyCatalogedObjectsAreSkipped(): void
    {
        $this->storageService->write($this->backend, 'web-01/2026/08/11/14-15.log.gz', 'hello');
        $this->storageService->write($this->backend, 'web-01/2026/08/11/14-15.meta.json', json_encode([
            'windowEnd' => '2026-08-11T14:30:00+00:00',
            'sizeBytes' => 5,
            'checksumSha256' => hash('sha256', 'hello'),
            'entryCount' => 1,
        ]));

        $this->tester->execute(['--backend' => (string) $this->backend->getId()]);
        self::assertCount(1, $this->em->getRepository(LogObject::class)->findAll());

        $tester2 = new CommandTester((new Application(self::$kernel))->find('app:log-catalog:reconcile'));
        $tester2->execute(['--backend' => (string) $this->backend->getId()]);

        self::assertStringContainsString('Already cataloged: 1', $tester2->getDisplay());
        self::assertCount(1, $this->em->getRepository(LogObject::class)->findAll());
    }

    public function testNonConformingKeysAreSkipped(): void
    {
        $this->storageService->write($this->backend, 'not-a-valid-key.log.gz', 'garbage');

        $exitCode = $this->tester->execute(['--backend' => (string) $this->backend->getId()]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Non-conforming keys skipped: 1', $this->tester->getDisplay());
        self::assertCount(0, $this->em->getRepository(LogObject::class)->findAll());
    }

    public function testUnknownBackendIdFails(): void
    {
        $exitCode = $this->tester->execute(['--backend' => '999999']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No storage backend found', $this->tester->getDisplay());
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
