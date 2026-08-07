<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

class SamlUser implements UserInterface
{
    public function __construct(
        private string $identifier,
        private array $attributes = [],
        private array $roles = ['ROLE_USER'],
    ) {}

    public function getUserIdentifier(): string { return $this->identifier; }
    public function getRoles(): array { return array_unique($this->roles); }
    public function eraseCredentials(): void {}

    public function getAttributes(): array { return $this->attributes; }

    public function getAttribute(string $name): mixed
    {
        $value = $this->attributes[$name] ?? null;
        return is_array($value) ? ($value[0] ?? null) : $value;
    }

    public function getEmail(): string { return $this->identifier; }

    public function getDisplayName(): string
    {
        $first = $this->getAttribute('firstName')
            ?? $this->getAttribute('http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname');
        $last = $this->getAttribute('lastName')
            ?? $this->getAttribute('http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname');

        if ($first && $last) {
            return "$first $last";
        }
        return $this->identifier;
    }

    public function __serialize(): array
    {
        return [
            'identifier' => $this->identifier,
            'attributes' => $this->attributes,
            'roles'      => $this->roles,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->identifier = $data['identifier'];
        $this->attributes = $data['attributes'];
        $this->roles      = $data['roles'];
    }
}
