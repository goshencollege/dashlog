<?php

namespace App\Tests\Service;

use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Service\StorageBackendFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class StorageBackendFactoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StorageBackendFactory $factory;
    private string $tmpDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->factory = self::getContainer()->get(StorageBackendFactory::class);

        $this->em->createQuery('DELETE FROM App\Entity\LogEntry')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\LogObject')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StorageBackend')->execute();

        $this->tmpDir = sys_get_temp_dir() . '/dashlog-backend-factory-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testRepeatedCallsForTheSameBackendReuseOneConnection(): void
    {
        $backend = $this->makeBackend();

        $first = $this->factory->createFilesystem($backend);
        $second = $this->factory->createFilesystem($backend);

        self::assertSame($first, $second);
    }

    public function testForgetForcesAFreshConnectionOnTheNextCall(): void
    {
        $backend = $this->makeBackend();

        $first = $this->factory->createFilesystem($backend);
        $this->factory->forget($backend);
        $second = $this->factory->createFilesystem($backend);

        self::assertNotSame($first, $second);
    }

    public function testEditingTheBackendBustsThePooledConnection(): void
    {
        $backend = $this->makeBackend();

        $first = $this->factory->createFilesystem($backend);

        $backend->setName('Renamed Backend'); // any real change — bumps updatedAt via AuditListener
        $this->em->flush();

        $second = $this->factory->createFilesystem($backend);

        self::assertNotSame($first, $second);
    }

    public function testAnUnpersistedBackendIsNeverPooled(): void
    {
        $backend = new StorageBackend();
        $backend->setType(StorageBackendType::Local);
        $backend->setPath($this->tmpDir);

        $first = $this->factory->createFilesystem($backend);
        $second = $this->factory->createFilesystem($backend);

        self::assertNotSame($first, $second);
    }

    private function makeBackend(): StorageBackend
    {
        $backend = new StorageBackend();
        $backend->setName('Test Backend');
        $backend->setType(StorageBackendType::Local);
        $backend->setPath($this->tmpDir);
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
