<?php

namespace App\Tests\Service;

use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use App\Service\EncryptionService;
use App\Service\StorageBackendFactory;
use App\Service\StorageService;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\TestCase;

class StorageServiceTest extends TestCase
{
    private string $tmpDir;
    private StorageService $storageService;
    private StorageBackend $backend;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/dashlog-storage-service-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);

        $encryptionKey = sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), SODIUM_BASE64_VARIANT_ORIGINAL);

        $this->storageService = new StorageService(new StorageBackendFactory(new EncryptionService($encryptionKey)));

        $this->backend = new StorageBackend();
        $this->backend->setType(StorageBackendType::Local);
        $this->backend->setPath($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $this->storageService->write($this->backend, 'web-01/2026/08/11/14-15.log.gz', 'log contents');

        self::assertTrue($this->storageService->exists($this->backend, 'web-01/2026/08/11/14-15.log.gz'));
        self::assertSame('log contents', $this->storageService->read($this->backend, 'web-01/2026/08/11/14-15.log.gz'));
    }

    public function testExistsIsFalseForMissingKey(): void
    {
        self::assertFalse($this->storageService->exists($this->backend, 'does/not/exist.log.gz'));
    }

    public function testDeleteRemovesTheObject(): void
    {
        $this->storageService->write($this->backend, 'probe.log.gz', 'contents');
        $this->storageService->delete($this->backend, 'probe.log.gz');

        self::assertFalse($this->storageService->exists($this->backend, 'probe.log.gz'));
    }

    public function testReadOfMissingKeyThrows(): void
    {
        $this->expectException(UnableToReadFile::class);

        $this->storageService->read($this->backend, 'does/not/exist.log.gz');
    }

    public function testListYieldsAllWrittenObjectsRecursively(): void
    {
        $this->storageService->write($this->backend, 'web-01/2026/08/11/14-15.log.gz', 'aaaa');
        $this->storageService->write($this->backend, 'web-02/2026/08/11/14-30.log.gz', 'bb');

        $keys = [];
        $sizeByKey = [];
        foreach ($this->storageService->list($this->backend) as $item) {
            $keys[] = $item['key'];
            $sizeByKey[$item['key']] = $item['size'];
        }

        sort($keys);
        self::assertSame(['web-01/2026/08/11/14-15.log.gz', 'web-02/2026/08/11/14-30.log.gz'], $keys);
        self::assertSame(4, $sizeByKey['web-01/2026/08/11/14-15.log.gz']);
        self::assertSame(2, $sizeByKey['web-02/2026/08/11/14-30.log.gz']);
    }

    public function testListRespectsPrefix(): void
    {
        $this->storageService->write($this->backend, 'web-01/2026/08/11/14-15.log.gz', 'aaaa');
        $this->storageService->write($this->backend, 'web-02/2026/08/11/14-30.log.gz', 'bb');

        $keys = array_column(iterator_to_array($this->storageService->list($this->backend, 'web-01')), 'key');

        self::assertSame(['web-01/2026/08/11/14-15.log.gz'], $keys);
    }

    public function testRetriesOnceWithAFreshConnectionAfterAFailure(): void
    {
        file_put_contents($this->tmpDir . '/probe.log.gz', 'contents');
        $badFilesystem = new Filesystem(new LocalFilesystemAdapter($this->tmpDir . '/does-not-exist'));
        $goodFilesystem = new Filesystem(new LocalFilesystemAdapter($this->tmpDir));

        $factory = $this->createMock(StorageBackendFactory::class);
        $factory->expects(self::exactly(2))
            ->method('createFilesystem')
            ->with($this->backend)
            ->willReturnOnConsecutiveCalls($badFilesystem, $goodFilesystem);
        $factory->expects(self::once())->method('forget')->with($this->backend);

        $storageService = new StorageService($factory);

        self::assertSame('contents', $storageService->read($this->backend, 'probe.log.gz'));
    }

    public function testGivesUpAfterASecondFailure(): void
    {
        $badFilesystem = new Filesystem(new LocalFilesystemAdapter($this->tmpDir . '/does-not-exist'));

        $factory = $this->createMock(StorageBackendFactory::class);
        $factory->expects(self::exactly(2))->method('createFilesystem')->willReturn($badFilesystem);
        $factory->expects(self::once())->method('forget');

        $storageService = new StorageService($factory);

        $this->expectException(UnableToReadFile::class);
        $storageService->read($this->backend, 'probe.log.gz');
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
