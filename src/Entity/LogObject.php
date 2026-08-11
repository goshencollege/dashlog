<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\LogObjectRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LogObjectRepository::class)]
#[ORM\Table(name: 'log_object')]
#[ORM\UniqueConstraint(name: 'uniq_backend_object_key', columns: ['storage_backend_id', 'object_key'])]
#[ORM\Index(name: 'idx_source_window_start', columns: ['source', 'window_start'])]
class LogObject
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: StorageBackend::class)]
    #[ORM\JoinColumn(nullable: false)]
    private StorageBackend $storageBackend;

    // Kept well under 767 chars (not the full 1024 StorageBackend paths allow) so the
    // (storage_backend_id, object_key) unique index fits InnoDB's 3072-byte key limit
    // under utf8mb4 (4 bytes/char).
    #[ORM\Column(length: 512)]
    private string $objectKey = '';

    #[ORM\Column(length: 255)]
    private string $source = '';

    #[ORM\Column(length: 20)]
    private string $tier = '';

    #[ORM\Column]
    private \DateTimeImmutable $windowStart;

    #[ORM\Column]
    private \DateTimeImmutable $windowEnd;

    #[ORM\Column]
    private int $sizeBytes = 0;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $checksumSha256 = null;

    #[ORM\Column(nullable: true)]
    private ?int $entryCount = null;

    #[ORM\Column(length: 20)]
    private string $status = 'staged';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getStorageBackend(): StorageBackend { return $this->storageBackend; }
    public function setStorageBackend(StorageBackend $storageBackend): static { $this->storageBackend = $storageBackend; return $this; }

    public function getObjectKey(): string { return $this->objectKey; }
    public function setObjectKey(string $objectKey): static { $this->objectKey = $objectKey; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $source): static { $this->source = $source; return $this; }

    public function getTier(): string { return $this->tier; }
    public function setTier(string $tier): static { $this->tier = $tier; return $this; }

    public function getWindowStart(): \DateTimeImmutable { return $this->windowStart; }
    public function setWindowStart(\DateTimeImmutable $windowStart): static { $this->windowStart = $windowStart; return $this; }

    public function getWindowEnd(): \DateTimeImmutable { return $this->windowEnd; }
    public function setWindowEnd(\DateTimeImmutable $windowEnd): static { $this->windowEnd = $windowEnd; return $this; }

    public function getSizeBytes(): int { return $this->sizeBytes; }
    public function setSizeBytes(int $sizeBytes): static { $this->sizeBytes = $sizeBytes; return $this; }

    public function getChecksumSha256(): ?string { return $this->checksumSha256; }
    public function setChecksumSha256(?string $checksumSha256): static { $this->checksumSha256 = $checksumSha256; return $this; }

    public function getEntryCount(): ?int { return $this->entryCount; }
    public function setEntryCount(?int $entryCount): static { $this->entryCount = $entryCount; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $lastError): static { $this->lastError = $lastError; return $this; }
}
