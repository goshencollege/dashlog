<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class SamlUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // Users are created by SamlAuthenticator from SAML assertions, not loaded here.
        throw new UnsupportedUserException('Users must authenticate via SAML.');
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof SamlUser) {
            throw new UnsupportedUserException(sprintf('Expected %s, got %s.', SamlUser::class, $user::class));
        }
        // User is stored in the session with all attributes — return as-is.
        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return $class === SamlUser::class || is_subclass_of($class, SamlUser::class);
    }
}
