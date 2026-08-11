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

class LogObjectMigrateCommandTest extends KernelTestCase
{
    private string $sourceDir;
    private string $destDir;
    private EntityManagerInterface $em;
    private StorageService $storageService;
    private StorageBackend $source;
    private StorageBackend $destination;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->storageService = self::getContainer()->get(StorageService::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();

        $this->sourceDir = sys_get_temp_dir() . '/dashlog-migratecmd-src-' . bin2hex(random_bytes(4));
        $this->destDir = sys_get_temp_dir() . '/dashlog-migratecmd-dst-' . bin2hex(random_bytes(4));
        mkdir($this->sourceDir);
        mkdir($this->destDir);

        $this->source = $this->makeBackend('Source', $this->sourceDir);
        $this->destination = $this->makeBackend('Destination', $this->destDir);

        $application = new Application($kernel);
        $this->tester = new CommandTester($application->find('app:log-objects:migrate'));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->sourceDir);
        $this->removeDirectory($this->destDir);
        parent::tearDown();
    }

    public function testMigratesAllObjectsFromSourceToDestination(): void
    {
        $this->writeObject($this->source, 'web-01/2026/08/11/14-15.log.gz', 'one');
        $this->writeObject($this->source, 'web-02/2026/08/11/14-15.log.gz', 'two');

        $exitCode = $this->tester->execute([
            '--from' => (string) $this->source->getId(),
            '--to' => (string) $this->destination->getId(),
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Migrated all log objects', $this->tester->getDisplay());

        foreach ($this->em->getRepository(LogObject::class)->findAll() as $logObject) {
            self::assertSame($this->destination->getId(), $logObject->getStorageBackend()->getId());
        }
    }

    public function testDryRunListsWithoutMigrating(): void
    {
        $logObject = $this->writeObject($this->source, 'web-01/2026/08/11/14-15.log.gz', 'one');

        $exitCode = $this->tester->execute([
            '--from' => (string) $this->source->getId(),
            '--to' => (string) $this->destination->getId(),
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('web-01/2026/08/11/14-15.log.gz', $this->tester->getDisplay());
        self::assertSame($this->source->getId(), $logObject->getStorageBackend()->getId());
        self::assertTrue($this->storageService->exists($this->source, 'web-01/2026/08/11/14-15.log.gz'));
    }

    public function testUnknownBackendIdFails(): void
    {
        $exitCode = $this->tester->execute([
            '--from' => '999999',
            '--to' => (string) $this->destination->getId(),
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No storage backend found', $this->tester->getDisplay());
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

    private function makeBackend(string $name, string $path): StorageBackend
    {
        $backend = new StorageBackend();
        $backend->setName($name);
        $backend->setType(StorageBackendType::Local);
        $backend->setPath($path);
        $backend->setIsActive(true);
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
