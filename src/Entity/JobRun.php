<?php

namespace App\Entity;

use App\Repository\JobRunRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per scheduled job (tiering, spool drain, etc.), tracking when it
 * last ran and how it went — upserted in place rather than kept as a
 * history, since the health page only needs the most recent outcome.
 */
#[ORM\Entity(repositoryClass: JobRunRepository::class)]
#[ORM\Table(name: 'job_run')]
class JobRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $jobName;

    #[ORM\Column]
    private \DateTimeImmutable $lastRunAt;

    #[ORM\Column(length: 20)]
    private string $status = 'success';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    public function __construct(string $jobName)
    {
        $this->jobName = $jobName;
    }

    public function getId(): ?int { return $this->id; }

    public function getJobName(): string { return $this->jobName; }

    public function getLastRunAt(): \DateTimeImmutable { return $this->lastRunAt; }
    public function setLastRunAt(\DateTimeImmutable $lastRunAt): static { $this->lastRunAt = $lastRunAt; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $lastError): static { $this->lastError = $lastError; return $this; }
}
