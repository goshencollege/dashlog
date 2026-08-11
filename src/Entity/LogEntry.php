<?php

namespace App\Entity;

use App\Repository\LogEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The structured, per-message search/browse index. One row per log line,
 * pointing back at the LogObject batch that holds its full raw content.
 * Derived data: droppable and rebuildable by re-reading LogObjects.
 */
#[ORM\Entity(repositoryClass: LogEntryRepository::class)]
#[ORM\Table(name: 'log_entry')]
#[ORM\Index(name: 'idx_log_entry_source_timestamp', columns: ['source', 'timestamp'])]
class LogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LogObject::class)]
    #[ORM\JoinColumn(nullable: false)]
    private LogObject $logObject;

    #[ORM\Column(length: 255)]
    private string $source = '';

    #[ORM\Column]
    private \DateTimeImmutable $timestamp;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $host = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $appName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $procId = null;

    #[ORM\Column(nullable: true)]
    private ?int $severity = null;

    #[ORM\Column(nullable: true)]
    private ?int $facility = null;

    #[ORM\Column(type: 'text')]
    private string $message = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getLogObject(): LogObject { return $this->logObject; }
    public function setLogObject(LogObject $logObject): static { $this->logObject = $logObject; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $source): static { $this->source = $source; return $this; }

    public function getTimestamp(): \DateTimeImmutable { return $this->timestamp; }
    public function setTimestamp(\DateTimeImmutable $timestamp): static { $this->timestamp = $timestamp; return $this; }

    public function getHost(): ?string { return $this->host; }
    public function setHost(?string $host): static { $this->host = $host; return $this; }

    public function getAppName(): ?string { return $this->appName; }
    public function setAppName(?string $appName): static { $this->appName = $appName; return $this; }

    public function getProcId(): ?string { return $this->procId; }
    public function setProcId(?string $procId): static { $this->procId = $procId; return $this; }

    public function getSeverity(): ?int { return $this->severity; }
    public function setSeverity(?int $severity): static { $this->severity = $severity; return $this; }

    public function getFacility(): ?int { return $this->facility; }
    public function setFacility(?int $facility): static { $this->facility = $facility; return $this; }

    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): static { $this->message = $message; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
