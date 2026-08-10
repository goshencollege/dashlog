<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Enum\StorageBackendType;
use App\Repository\StorageBackendRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: StorageBackendRepository::class)]
#[ORM\Table(name: 'storage_backend')]
#[Assert\Callback('validateTypeSpecificFields')]
class StorageBackend
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(length: 20, enumType: StorageBackendType::class)]
    private StorageBackendType $type = StorageBackendType::Local;

    #[ORM\Column]
    private bool $isActive = true;

    // Local
    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $path = null;

    // CIFS / SMB
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cifsHost = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cifsShare = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $cifsRemotePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cifsUsername = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cifsDomain = null;

    /** Stored encrypted via EncryptionService. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cifsPassword = null;

    // S3
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $s3Bucket = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $s3Region = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $s3Endpoint = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $s3PathPrefix = null;

    #[ORM\Column]
    private bool $s3UsePathStyleEndpoint = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $s3AccessKeyId = null;

    /** Stored encrypted via EncryptionService. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $s3SecretAccessKey = null;

    // Connectivity status, populated by the "Test Connection" action.
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $lastCheckStatus = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastCheckMessage = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getType(): StorageBackendType { return $this->type; }
    public function setType(StorageBackendType $type): static { $this->type = $type; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getPath(): ?string { return $this->path; }
    public function setPath(?string $path): static { $this->path = $path; return $this; }

    public function getCifsHost(): ?string { return $this->cifsHost; }
    public function setCifsHost(?string $cifsHost): static { $this->cifsHost = $cifsHost; return $this; }

    public function getCifsShare(): ?string { return $this->cifsShare; }
    public function setCifsShare(?string $cifsShare): static { $this->cifsShare = $cifsShare; return $this; }

    public function getCifsRemotePath(): ?string { return $this->cifsRemotePath; }
    public function setCifsRemotePath(?string $cifsRemotePath): static { $this->cifsRemotePath = $cifsRemotePath; return $this; }

    public function getCifsUsername(): ?string { return $this->cifsUsername; }
    public function setCifsUsername(?string $cifsUsername): static { $this->cifsUsername = $cifsUsername; return $this; }

    public function getCifsDomain(): ?string { return $this->cifsDomain; }
    public function setCifsDomain(?string $cifsDomain): static { $this->cifsDomain = $cifsDomain; return $this; }

    public function getCifsPassword(): ?string { return $this->cifsPassword; }
    public function setCifsPassword(?string $cifsPassword): static { $this->cifsPassword = $cifsPassword; return $this; }

    public function getS3Bucket(): ?string { return $this->s3Bucket; }
    public function setS3Bucket(?string $s3Bucket): static { $this->s3Bucket = $s3Bucket; return $this; }

    public function getS3Region(): ?string { return $this->s3Region; }
    public function setS3Region(?string $s3Region): static { $this->s3Region = $s3Region; return $this; }

    public function getS3Endpoint(): ?string { return $this->s3Endpoint; }
    public function setS3Endpoint(?string $s3Endpoint): static { $this->s3Endpoint = $s3Endpoint; return $this; }

    public function getS3PathPrefix(): ?string { return $this->s3PathPrefix; }
    public function setS3PathPrefix(?string $s3PathPrefix): static { $this->s3PathPrefix = $s3PathPrefix; return $this; }

    public function isS3UsePathStyleEndpoint(): bool { return $this->s3UsePathStyleEndpoint; }
    public function setS3UsePathStyleEndpoint(bool $s3UsePathStyleEndpoint): static { $this->s3UsePathStyleEndpoint = $s3UsePathStyleEndpoint; return $this; }

    public function getS3AccessKeyId(): ?string { return $this->s3AccessKeyId; }
    public function setS3AccessKeyId(?string $s3AccessKeyId): static { $this->s3AccessKeyId = $s3AccessKeyId; return $this; }

    public function getS3SecretAccessKey(): ?string { return $this->s3SecretAccessKey; }
    public function setS3SecretAccessKey(?string $s3SecretAccessKey): static { $this->s3SecretAccessKey = $s3SecretAccessKey; return $this; }

    public function getLastCheckedAt(): ?\DateTimeImmutable { return $this->lastCheckedAt; }
    public function setLastCheckedAt(?\DateTimeImmutable $lastCheckedAt): static { $this->lastCheckedAt = $lastCheckedAt; return $this; }

    public function getLastCheckStatus(): ?string { return $this->lastCheckStatus; }
    public function setLastCheckStatus(?string $lastCheckStatus): static { $this->lastCheckStatus = $lastCheckStatus; return $this; }

    public function getLastCheckMessage(): ?string { return $this->lastCheckMessage; }
    public function setLastCheckMessage(?string $lastCheckMessage): static { $this->lastCheckMessage = $lastCheckMessage; return $this; }

    public function validateTypeSpecificFields(ExecutionContextInterface $context): void
    {
        match ($this->type) {
            StorageBackendType::Local => $this->requireFields($context, ['path' => $this->path]),
            StorageBackendType::Cifs => $this->requireFields($context, [
                'cifsHost' => $this->cifsHost,
                'cifsShare' => $this->cifsShare,
                'cifsUsername' => $this->cifsUsername,
            ]),
            StorageBackendType::S3 => $this->requireFields($context, [
                's3Bucket' => $this->s3Bucket,
                's3Region' => $this->s3Region,
                's3AccessKeyId' => $this->s3AccessKeyId,
            ]),
        };
    }

    /** @param array<string, ?string> $fields */
    private function requireFields(ExecutionContextInterface $context, array $fields): void
    {
        foreach ($fields as $property => $value) {
            if ($value === null || trim($value) === '') {
                $context->buildViolation('This field is required for the selected backend type.')
                    ->atPath($property)
                    ->addViolation();
            }
        }
    }
}
