<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\SamlProviderRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SamlProviderRepository::class)]
#[ORM\Table(name: 'saml_provider')]
class SamlProvider
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column]
    private bool $isActive = false;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private string $spEntityId = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private string $spAcsUrl = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private string $spSloUrl = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $spCert = '';

    /** Stored encrypted via EncryptionService. */
    #[ORM\Column(type: 'text')]
    private string $spPrivateKey = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $idpEntityId = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private string $idpSsoUrl = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $idpCert = '';

    #[ORM\Column]
    #[Assert\Range(min: 1, max: 1440)]
    private int $sessionLifetimeMinutes = 30;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $roleAttribute = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getSpEntityId(): string { return $this->spEntityId; }
    public function setSpEntityId(string $spEntityId): static { $this->spEntityId = $spEntityId; return $this; }

    public function getSpAcsUrl(): string { return $this->spAcsUrl; }
    public function setSpAcsUrl(string $spAcsUrl): static { $this->spAcsUrl = $spAcsUrl; return $this; }

    public function getSpSloUrl(): string { return $this->spSloUrl; }
    public function setSpSloUrl(string $spSloUrl): static { $this->spSloUrl = $spSloUrl; return $this; }

    public function getSpCert(): string { return $this->spCert; }
    public function setSpCert(string $spCert): static { $this->spCert = trim($spCert); return $this; }

    public function getSpPrivateKey(): string { return $this->spPrivateKey; }
    public function setSpPrivateKey(string $spPrivateKey): static { $this->spPrivateKey = $spPrivateKey; return $this; }

    public function getIdpEntityId(): string { return $this->idpEntityId; }
    public function setIdpEntityId(string $idpEntityId): static { $this->idpEntityId = $idpEntityId; return $this; }

    public function getIdpSsoUrl(): string { return $this->idpSsoUrl; }
    public function setIdpSsoUrl(string $idpSsoUrl): static { $this->idpSsoUrl = $idpSsoUrl; return $this; }

    public function getIdpCert(): string { return $this->idpCert; }
    public function setIdpCert(string $idpCert): static { $this->idpCert = trim($idpCert); return $this; }

    public function getSessionLifetimeMinutes(): int { return $this->sessionLifetimeMinutes; }
    public function setSessionLifetimeMinutes(int $sessionLifetimeMinutes): static { $this->sessionLifetimeMinutes = $sessionLifetimeMinutes; return $this; }

    public function getRoleAttribute(): ?string { return $this->roleAttribute; }
    public function setRoleAttribute(?string $roleAttribute): static { $this->roleAttribute = $roleAttribute; return $this; }
}
