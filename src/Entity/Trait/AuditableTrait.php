<?php

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

trait AuditableTrait
{
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $updatedBy = null;

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $dt): static { $this->createdAt = $dt; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $dt): static { $this->updatedAt = $dt; return $this; }

    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function setCreatedBy(?string $user): static { $this->createdBy = $user; return $this; }

    public function getUpdatedBy(): ?string { return $this->updatedBy; }
    public function setUpdatedBy(?string $user): static { $this->updatedBy = $user; return $this; }
}
