<?php

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

trait SoftDeletableTrait
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }

    public function softDelete(): static
    {
        $this->deletedAt = new \DateTimeImmutable();
        return $this;
    }

    public function restore(): static
    {
        $this->deletedAt = null;
        return $this;
    }
}
