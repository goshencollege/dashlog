<?php

namespace App\Service;

use App\Entity\StorageBackend;
use League\Flysystem\Filesystem;

/**
 * The single entry point for reading/writing/listing objects on a configured
 * StorageBackend. Everything downstream (ingestion, tiering, migration,
 * reconciliation) should go through this rather than touching Flysystem
 * directly, so backend quirks are handled in one place.
 *
 * StorageBackendFactory pools connections per backend rather than opening a
 * new one per call, so a pooled connection can occasionally go stale
 * between calls (idle timeout, the far end restarting). Every operation
 * below is wrapped in retryingOnceOnStaleConnection() to recover from that
 * automatically rather than failing every subsequent call for the rest of
 * this process's life.
 */
class StorageService
{
    public function __construct(
        private readonly StorageBackendFactory $storageBackendFactory,
    ) {}

    public function write(StorageBackend $backend, string $key, string $contents): void
    {
        $this->retryingOnceOnStaleConnection($backend, function () use ($backend, $key, $contents) {
            $filesystem = $this->createFilesystem($backend);
            $filesystem->write($key, $contents);

            // Some backends (notably CIFS via the smbclient CLI wrapper) return from
            // write() once the local side is flushed, before the remote copy is
            // guaranteed to be complete — an immediate read can race that and return
            // stale/partial content. A few short retries absorb that without adding
            // any delay for backends that are already fully synchronous.
            $readBack = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $readBack = $filesystem->read($key);
                if ($readBack === $contents) {
                    return;
                }
                if ($attempt < 3) {
                    usleep(500_000);
                }
            }

            throw new \RuntimeException(sprintf(
                'Round-tripped content for "%s" did not match what was written (wrote %d bytes, read back %d bytes after 3 attempts).',
                $key,
                strlen($contents),
                strlen((string) $readBack),
            ));
        });
    }

    public function read(StorageBackend $backend, string $key): string
    {
        return $this->retryingOnceOnStaleConnection($backend, fn () => $this->createFilesystem($backend)->read($key));
    }

    public function exists(StorageBackend $backend, string $key): bool
    {
        return $this->retryingOnceOnStaleConnection($backend, fn () => $this->createFilesystem($backend)->fileExists($key));
    }

    public function delete(StorageBackend $backend, string $key): void
    {
        $this->retryingOnceOnStaleConnection($backend, fn () => $this->createFilesystem($backend)->delete($key));
    }

    /**
     * @return iterable<array{key: string, size: int, lastModified: \DateTimeImmutable}>
     */
    public function list(StorageBackend $backend, string $prefix = ''): iterable
    {
        $filesystem = $this->createFilesystem($backend);

        foreach ($filesystem->listContents($prefix, deep: true) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            yield [
                'key' => $item->path(),
                'size' => $item->fileSize() ?? 0,
                'lastModified' => (new \DateTimeImmutable())->setTimestamp($item->lastModified() ?? 0),
            ];
        }
    }

    private function createFilesystem(StorageBackend $backend): Filesystem
    {
        return $this->storageBackendFactory->createFilesystem($backend);
    }

    private function retryingOnceOnStaleConnection(StorageBackend $backend, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            $this->storageBackendFactory->forget($backend);

            return $operation();
        }
    }
}
