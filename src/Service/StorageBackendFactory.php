<?php

namespace App\Service;

use App\Entity\StorageBackend;
use App\Enum\StorageBackendType;
use Aws\S3\S3Client;
use Icewind\SMB\BasicAuth;
use Icewind\SMB\ServerFactory;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RobGridley\Flysystem\Smb\SmbAdapter;

class StorageBackendFactory
{
    /**
     * One connection per backend, reused for the life of this service
     * instance, rather than opened fresh on every call — a single spool
     * drain sweep can touch the same backend hundreds of times, and
     * building a brand-new CIFS/S3 session for each one is both wasteful
     * and, in practice, enough connection churn for a NAS to start
     * throttling or rejecting the source IP.
     *
     * @var array<string, Filesystem>
     */
    private array $filesystems = [];

    public function __construct(
        private readonly EncryptionService $encryption,
    ) {}

    public function createFilesystem(StorageBackend $backend): Filesystem
    {
        $key = $this->cacheKey($backend);
        if ($key === null) {
            return new Filesystem($this->createAdapter($backend));
        }

        return $this->filesystems[$key] ??= new Filesystem($this->createAdapter($backend));
    }

    /**
     * Drops the pooled connection for $backend, if any, so the next
     * createFilesystem() call builds a fresh one. Used by StorageService to
     * recover from a connection that's gone stale between calls (idle
     * timeout, the far end restarting, etc.) without needing this whole
     * process to restart.
     */
    public function forget(StorageBackend $backend): void
    {
        $key = $this->cacheKey($backend);
        if ($key !== null) {
            unset($this->filesystems[$key]);
        }
    }

    /**
     * Keyed by id and updatedAt so an edited backend (new credentials, a
     * different host/share) gets a fresh connection instead of reusing one
     * built from now-stale config. Null for a not-yet-persisted backend —
     * there's nothing meaningful to key on, so it's simply never pooled.
     */
    private function cacheKey(StorageBackend $backend): ?string
    {
        if ($backend->getId() === null) {
            return null;
        }

        // Microsecond precision matters here: two edits to the same backend
        // within the same wall-clock second are otherwise indistinguishable
        // (the DB column itself only stores whole seconds, but the
        // in-memory value AuditListener sets on preUpdate carries whatever
        // precision the platform clock gives new \DateTimeImmutable()).
        return $backend->getId() . ':' . $backend->getUpdatedAt()->format('Y-m-d H:i:s.u');
    }

    private function createAdapter(StorageBackend $backend): LocalFilesystemAdapter|SmbAdapter|AwsS3V3Adapter
    {
        return match ($backend->getType()) {
            StorageBackendType::Local => new LocalFilesystemAdapter((string) $backend->getPath()),
            StorageBackendType::Cifs => $this->createSmbAdapter($backend),
            StorageBackendType::S3 => $this->createS3Adapter($backend),
        };
    }

    private function createSmbAdapter(StorageBackend $backend): SmbAdapter
    {
        $auth = new BasicAuth(
            (string) $backend->getCifsUsername(),
            $backend->getCifsDomain(),
            $this->encryption->decrypt((string) $backend->getCifsPassword()),
        );

        $server = (new ServerFactory())->createServer((string) $backend->getCifsHost(), $auth);
        $share  = $server->getShare((string) $backend->getCifsShare());

        return new SmbAdapter($share, (string) $backend->getCifsRemotePath());
    }

    private function createS3Adapter(StorageBackend $backend): AwsS3V3Adapter
    {
        $config = [
            'region'      => (string) $backend->getS3Region(),
            'version'     => 'latest',
            'credentials' => [
                'key'    => (string) $backend->getS3AccessKeyId(),
                'secret' => $this->encryption->decrypt((string) $backend->getS3SecretAccessKey()),
            ],
        ];

        if ($backend->getS3Endpoint() !== null && $backend->getS3Endpoint() !== '') {
            $config['endpoint'] = $backend->getS3Endpoint();
        }

        if ($backend->isS3UsePathStyleEndpoint()) {
            $config['use_path_style_endpoint'] = true;
        }

        $client = new S3Client($config);

        return new AwsS3V3Adapter($client, (string) $backend->getS3Bucket(), (string) $backend->getS3PathPrefix());
    }
}
