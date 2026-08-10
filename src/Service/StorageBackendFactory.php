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
    public function __construct(
        private readonly EncryptionService $encryption,
    ) {}

    public function createFilesystem(StorageBackend $backend): Filesystem
    {
        return new Filesystem($this->createAdapter($backend));
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
